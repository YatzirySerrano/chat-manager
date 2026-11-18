<?php

use App\Enums\AuditAction;
use App\Enums\ConversationEventType;
use App\Enums\ConversationReadiness;
use App\Enums\ConversationStatus;
use App\Enums\DataScope;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\Permission;
use App\Jobs\DeliverOutboundMessage;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use App\Services\Meta\SendResult;
use App\Services\Meta\WhatsAppTransport;
use App\Services\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;

/*
| «Marcar que necesita información» with the catalog of missing information
| (Configuración › Información faltante): the agent ticks items and/or
| writes «Otro»; the list is the recorded reason and, on request, is sent to
| the customer as an ordinary reply of the agent. A message that cannot be
| sent never undoes the change: the toast explains why.
*/

beforeEach(function () {
    config(['features.phase_two' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-11-18 16:00:00', 'UTC'));
    Queue::fake([DeliverOutboundMessage::class]);

    app(PlatformSettings::class)->update(['missing_info' => [
        'intro' => 'Para continuar con tu solicitud nos falta:',
        'items' => ['INE por ambos lados', 'Comprobante de domicilio', 'CURP'],
    ]]);

    $this->department = Department::factory()->create(['service_rules' => ['intake.confirmation_required' => true]]);
    $this->agent = conversationUser(
        [Permission::ViewConversations, Permission::AcceptConversations, Permission::ReplyConversations, Permission::ConfirmConversationReady],
        DataScope::Departments,
        [$this->department],
    );
    $this->open = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create([
        'last_inbound_at' => CarbonImmutable::parse('2026-11-18 15:30:00', 'UTC'),
    ]);
});

function markMissing(object $test, User $actor, Conversation $conversation, array $data): TestResponse
{
    return $test->actingAs($actor)->post(route('conversations.readiness.update', $conversation), ['readiness' => 'needs_info', ...$data]);
}

test('the picked items are recorded and sent to the customer as a reply of the agent', function () {
    markMissing($this, $this->agent, $this->open, [
        'missing_items' => ['Comprobante de domicilio', 'INE por ambos lados'],
        'missing_other' => '  Firma   del aval ',
        'send_message' => true,
        'client_reference' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'La solicitud quedó marcada como «Necesita información» y se envió al cliente lo que falta.']);

    $message = Message::query()->where('conversation_id', $this->open->id)->sole();

    expect($this->open->fresh()->readiness)->toBe(ConversationReadiness::NeedsInfo)
        ->and($message->direction)->toBe(MessageDirection::Outbound)
        ->and($message->sender_user_id)->toBe($this->agent->id)
        // Catalog order, then «Otro».
        ->and($message->body)->toBe("Para continuar con tu solicitud nos falta:\n• INE por ambos lados\n• Comprobante de domicilio\n• Firma del aval")
        ->and($this->open->events()->where('type', ConversationEventType::ReadinessReverted)->sole()->note)
        ->toBe('INE por ambos lados; Comprobante de domicilio; Firma del aval')
        ->and(AuditLog::query()->where('action', AuditAction::ConversationReadinessReverted->value)->sole()->properties['reason'])
        ->toBe('INE por ambos lados; Comprobante de domicilio; Firma del aval');

    Queue::assertPushed(DeliverOutboundMessage::class, 1);
});

test('a single short item of the catalog is enough', function () {
    markMissing($this, $this->agent, $this->open, ['missing_items' => ['CURP']])->assertSessionHasNoErrors();

    expect($this->open->fresh()->readiness)->toBe(ConversationReadiness::NeedsInfo)
        ->and($this->open->events()->where('type', ConversationEventType::ReadinessReverted)->sole()->note)->toBe('CURP')
        ->and(Message::query()->count())->toBe(0);
});

test('a retried submission sends the message only once', function () {
    $data = ['missing_items' => ['CURP'], 'send_message' => true, 'client_reference' => (string) Str::uuid()];

    markMissing($this, $this->agent, $this->open, $data)->assertSessionHasNoErrors();
    markMissing($this, $this->agent, $this->open, $data)->assertSessionHasNoErrors();

    expect(Message::query()->where('conversation_id', $this->open->id)->count())->toBe(1);
});

test('nothing is sent when the agent unticks the message', function () {
    markMissing($this, $this->agent, $this->open, ['missing_items' => ['CURP'], 'send_message' => false])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'La solicitud quedó marcada como «Necesita información».']);

    expect($this->open->fresh()->readiness)->toBe(ConversationReadiness::NeedsInfo)
        ->and(Message::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('with the customer window closed the request is marked and the toast says why nothing was sent', function () {
    $this->open->forceFill(['last_inbound_at' => CarbonImmutable::parse('2026-11-16 10:00:00', 'UTC')])->save();

    markMissing($this, $this->agent, $this->open, [
        'missing_items' => ['CURP'],
        'send_message' => true,
        'client_reference' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.type', 'warning');

    $toast = session()->get(SessionKey::FLASH_DATA)['toast']['message'];

    expect($this->open->fresh()->readiness)->toBe(ConversationReadiness::NeedsInfo)
        ->and(Message::query()->count())->toBe(0)
        ->and($toast)->toStartWith('La solicitud quedó marcada como «Necesita información», pero el mensaje al cliente no se envió. ')
        ->and(mb_strlen($toast))->toBeGreaterThan(100);
    Queue::assertNothingPushed();
});

test('someone who may mark a pending request sends the list from the queue', function () {
    $supervisor = User::factory()->supervisor()->inDepartments($this->department)->create();
    $pending = Conversation::factory()->inDepartment($this->department)->create([
        'last_inbound_at' => CarbonImmutable::parse('2026-11-18 15:30:00', 'UTC'),
    ]);

    markMissing($this, $supervisor, $pending, [
        'missing_other' => 'Foto de la fachada',
        'send_message' => true,
        'client_reference' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'La solicitud quedó marcada como «Necesita información» y se envió al cliente lo que falta.']);

    $message = Message::query()->where('conversation_id', $pending->id)->sole();

    expect($pending->fresh()->readiness)->toBe(ConversationReadiness::NeedsInfo)
        ->and($message->sender_user_id)->toBe($supervisor->id)
        ->and($message->body)->toBe('Para continuar con tu solicitud nos falta:
• Foto de la fachada');
});

test('the list is delivered after the conversation went back to the queue', function () {
    $transport = new class implements WhatsAppTransport
    {
        /** @var list<int> */
        public array $sent = [];

        public function send(Message $message): SendResult
        {
            $this->sent[] = $message->id;

            return new SendResult('wamid.out.'.$message->id);
        }

        public function name(): string
        {
            return 'recording';
        }
    };
    app()->instance(WhatsAppTransport::class, $transport);

    markMissing($this, $this->agent, $this->open, [
        'missing_items' => ['CURP'],
        'send_message' => true,
        'client_reference' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();

    // The worker picks it up once the conversation is already pending, without agent.
    expect($this->open->fresh())->status->toBe(ConversationStatus::Pending)->assigned_user_id->toBeNull();

    $message = Message::query()->where('conversation_id', $this->open->id)->sole();
    app()->call([new DeliverOutboundMessage($message->id), 'handle']);

    expect($message->refresh()->status)->not->toBe(MessageStatus::Failed)
        ->and($message->error_code)->toBeNull()
        ->and($transport->sent)->toBe([$message->id]);
});

test('what is missing must be picked or described', function (array $data, string $field) {
    $this->actingAs($this->agent)
        ->postJson(route('conversations.readiness.update', $this->open), ['readiness' => 'needs_info', ...$data])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect($this->open->fresh()->readiness)->toBe(ConversationReadiness::Ready)
        ->and(Message::query()->count())->toBe(0);
})->with([
    'nothing selected' => [['missing_items' => [], 'send_message' => true, 'client_reference' => '6f1c2a4e-8b3d-4e7f-9a01-2c3d4e5f6a7b'], 'reason'],
    'an item outside the catalog' => [['missing_items' => ['Pasaporte']], 'missing_items.0'],
    'a too short other text' => [['missing_other' => 'RFC'], 'missing_other'],
    'sending without a reference' => [['missing_items' => ['CURP'], 'send_message' => true], 'client_reference'],
]);

test('the inbox receives the catalog while phase two is on', function () {
    $this->actingAs($this->agent)
        ->get(route('conversations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('missingInfo.intro', 'Para continuar con tu solicitud nos falta:')
            ->where('missingInfo.items', ['INE por ambos lados', 'Comprobante de domicilio', 'CURP']));

    config(['features.phase_two' => false]);

    $this->actingAs($this->agent)
        ->get(route('conversations.index'))
        ->assertInertia(fn (Assert $page) => $page->where('missingInfo', null));
});
