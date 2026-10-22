<?php

use App\Enums\ConversationEventType;
use App\Enums\CustomerWindowStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Jobs\DeliverOutboundMessage;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use App\Services\Conversations\CustomerServiceWindow;
use App\Services\Meta\WhatsAppTransport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
| WhatsApp's 24 hour customer service window. It depends only on the last
| customer message; agents' replies, notes, transfers and reopening never
| extend it. Delivery goes through a fake transport: no Meta traffic.
*/

/**
 * Transport double that records what would have been sent to Meta.
 */
function recordingTransport(): WhatsAppTransport
{
    $transport = new class implements WhatsAppTransport
    {
        /** @var list<int> */
        public array $sent = [];

        public function sendText(Message $message): string
        {
            $this->sent[] = $message->id;

            return 'wamid.out.'.$message->id;
        }
    };

    app()->instance(WhatsAppTransport::class, $transport);

    return $transport;
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 18:00:00', 'UTC'));
    $this->department = Department::factory()->create();
    $this->agent = User::factory()->agent()->inDepartments($this->department)->create();
});

function attendedConversation(User $agent, Department $department, ?CarbonImmutable $lastInbound): Conversation
{
    return Conversation::factory()->inDepartment($department)->assignedTo($agent)->create(['last_inbound_at' => $lastInbound]);
}

test('the window state follows the last customer message', function (?string $lastInbound, CustomerWindowStatus $status, int $remaining, bool $allows) {
    $state = app(CustomerServiceWindow::class)->at($lastInbound === null ? null : CarbonImmutable::parse($lastInbound, 'UTC'));

    expect($state->status)->toBe($status)
        ->and($state->remainingSeconds)->toBe($remaining)
        ->and($state->allowsFreeFormMessages())->toBe($allows)
        ->and($state->blockReason === null)->toBe($allows);
})->with([
    'customer never wrote' => [null, CustomerWindowStatus::None, 0, false],
    'customer just wrote' => ['2026-10-19 18:00:00', CustomerWindowStatus::Open, 86_400, true],
    'twenty hours ago' => ['2026-10-18 22:00:00', CustomerWindowStatus::Open, 14_400, true],
    'a few minutes left' => ['2026-10-18 18:10:00', CustomerWindowStatus::Expiring, 600, true],
    'exactly twenty four hours' => ['2026-10-18 18:00:00', CustomerWindowStatus::Expired, 0, false],
    'two days ago' => ['2026-10-17 12:00:00', CustomerWindowStatus::Expired, 0, false],
]);

test('the window is exposed in UTC with its expiration', function () {
    $state = app(CustomerServiceWindow::class)->at(CarbonImmutable::parse('2026-10-19 10:30:00', 'America/Mexico_City'));

    expect($state->toArray())->toMatchArray([
        'status' => 'open',
        'last_inbound_at' => '2026-10-19T16:30:00Z',
        'expires_at' => '2026-10-20T16:30:00Z',
        'allows_free_form' => true,
    ]);
});

test('a new customer message opens a new window; repeated or late events do not move it', function () {
    $connection = Connection::factory()->create();
    receiveWhatsapp($connection, at: CarbonImmutable::now()->subHours(30), id: 'wamid.old');
    $conversation = Conversation::query()->sole();

    expect(app(CustomerServiceWindow::class)->for($conversation)->status)->toBe(CustomerWindowStatus::Expired);

    receiveWhatsapp($connection, at: CarbonImmutable::now()->subMinutes(5), id: 'wamid.new');
    receiveWhatsapp($connection, at: CarbonImmutable::now()->subMinutes(5), id: 'wamid.new');
    receiveWhatsapp($connection, at: CarbonImmutable::now()->subHours(28), id: 'wamid.late');

    expect($conversation->refresh()->last_inbound_at->toDateTimeString())->toBe('2026-10-19 17:55:00')
        ->and(app(CustomerServiceWindow::class)->for($conversation)->status)->toBe(CustomerWindowStatus::Open);
});

test('notes, transfers, holds and reopening never extend the window', function () {
    $lastInbound = CarbonImmutable::now()->subHours(23);
    $conversation = attendedConversation($this->agent, $this->department, $lastInbound);
    $admin = User::factory()->administrator()->create();
    $colleague = User::factory()->agent()->available()->inDepartments($this->department)->create();

    $this->actingAs($this->agent)->post(route('conversations.notes.store', $conversation), ['body' => 'Nota']);
    $this->actingAs($this->agent)->post(route('conversations.hold', $conversation), ['version' => 0]);
    $this->actingAs($this->agent)->post(route('conversations.transfer', $conversation), ['user_id' => $colleague->id, 'version' => 1]);
    $this->actingAs($colleague)->post(route('conversations.close', $conversation), ['version' => 2]);
    $this->actingAs($admin)->post(route('conversations.reopen', $conversation), ['version' => 3]);

    expect($conversation->refresh()->version)->toBe(4)
        ->and($conversation->last_inbound_at->toDateTimeString())->toBe($lastInbound->toDateTimeString());
});

test('a reply inside the window is queued once and delivered, without extending the window', function () {
    $transport = recordingTransport();
    $lastInbound = CarbonImmutable::now()->subHours(2);
    $conversation = attendedConversation($this->agent, $this->department, $lastInbound);
    $reference = (string) Str::uuid();

    $this->actingAs($this->agent)
        ->post(route('conversations.messages.store', $conversation), ['body' => 'Con gusto le ayudo', 'client_reference' => $reference])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->agent)
        ->post(route('conversations.messages.store', $conversation), ['body' => 'Con gusto le ayudo', 'client_reference' => $reference])
        ->assertSessionHasNoErrors();

    $message = Message::query()->sole();

    expect($message)
        ->direction->toBe(MessageDirection::Outbound)
        ->type->toBe(MessageType::Text)
        ->status->toBe(MessageStatus::Sent)
        ->whatsapp_message_id->toBe('wamid.out.'.$message->id)
        ->sender_user_id->toBe($this->agent->id)
        ->and($transport->sent)->toBe([$message->id])
        ->and($conversation->refresh()->last_inbound_at->toDateTimeString())->toBe($lastInbound->toDateTimeString())
        ->and($conversation->first_response_at)->not->toBeNull();
});

test('replies are refused when the window is closed, through the page or the API', function (?int $hoursAgo) {
    Queue::fake();
    $conversation = attendedConversation($this->agent, $this->department, $hoursAgo === null ? null : CarbonImmutable::now()->subHours($hoursAgo));

    $this->actingAs($this->agent)
        ->from(route('conversations.show', $conversation))
        ->post(route('conversations.messages.store', $conversation), ['body' => 'Hola', 'client_reference' => (string) Str::uuid()])
        ->assertSessionHasErrors('conversation');

    $this->actingAs($this->agent)
        ->postJson(route('conversations.messages.store', $conversation), ['body' => 'Hola', 'client_reference' => (string) Str::uuid(), 'type' => 'template', 'template' => ['name' => 'promo']])
        ->assertStatus(409);

    expect(Message::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['expired' => [25], 'customer never wrote' => [null]]);

test('a reply queued while the window was open is not sent when the worker runs after it expired', function () {
    Queue::fake();
    $transport = recordingTransport();
    $conversation = attendedConversation($this->agent, $this->department, CarbonImmutable::now()->subHours(23)->subMinutes(55));

    $this->actingAs($this->agent)
        ->post(route('conversations.messages.store', $conversation), ['body' => 'Le confirmo en un momento', 'client_reference' => (string) Str::uuid()])
        ->assertSessionHasNoErrors();

    $message = Message::query()->sole();
    expect($message->status)->toBe(MessageStatus::Queued);
    Queue::assertPushed(DeliverOutboundMessage::class, fn (DeliverOutboundMessage $job): bool => $job->messageId === $message->id);

    $this->travel(10)->minutes();
    app()->call([new DeliverOutboundMessage($message->id), 'handle']);

    expect($message->refresh())
        ->status->toBe(MessageStatus::Failed)
        ->error_code->toBe('customer_window_closed')
        ->whatsapp_message_id->toBeNull()
        ->and($transport->sent)->toBe([])
        ->and($conversation->events()->where('type', ConversationEventType::OutboundBlocked)->exists())->toBeTrue();

    app()->call([new DeliverOutboundMessage($message->id), 'handle']);
    expect($transport->sent)->toBe([]);
});

test('a reply queued before its connection was deleted or deactivated is never sent', function (array $change) {
    Queue::fake();
    $transport = recordingTransport();
    $conversation = attendedConversation($this->agent, $this->department, CarbonImmutable::now()->subHour());

    $this->actingAs($this->agent)
        ->post(route('conversations.messages.store', $conversation), ['body' => 'Le confirmo en un momento', 'client_reference' => (string) Str::uuid()])
        ->assertSessionHasNoErrors();

    $message = Message::query()->sole();
    $conversation->channelConnection()->firstOrFail()->forceFill($change)->save();

    app()->call([new DeliverOutboundMessage($message->id), 'handle']);

    expect($message->refresh())
        ->status->toBe(MessageStatus::Failed)
        ->error_code->toBe('connection_unavailable')
        ->and($transport->sent)->toBe([])
        ->and($conversation->events()->where('type', ConversationEventType::OutboundBlocked)->exists())->toBeTrue();
})->with([
    'deleted' => [['deleted_at' => now(), 'is_active' => false]],
    'deactivated' => [['is_active' => false]],
]);

test('without the WhatsApp integration a reply is marked as not sent instead of pretending delivery', function () {
    $conversation = attendedConversation($this->agent, $this->department, CarbonImmutable::now()->subHour());

    $this->actingAs($this->agent)
        ->post(route('conversations.messages.store', $conversation), ['body' => 'Hola', 'client_reference' => (string) Str::uuid()])
        ->assertSessionHasNoErrors();

    expect(Message::query()->sole())
        ->status->toBe(MessageStatus::Failed)
        ->error_code->toBe('transport_unavailable')
        ->whatsapp_message_id->toBeNull();
});

test('only the responsible agent replies, and only while attending', function () {
    Queue::fake();
    $conversation = attendedConversation($this->agent, $this->department, CarbonImmutable::now()->subHour());
    $supervisor = User::factory()->supervisor()->inDepartments($this->department)->create();
    $send = fn (User $user) => $this->actingAs($user)->postJson(route('conversations.messages.store', $conversation), ['body' => 'Hola', 'client_reference' => (string) Str::uuid()]);

    $send($supervisor)->assertForbidden();
    $send(User::factory()->administrator()->create())->assertForbidden();

    $conversation->forceFill(['status' => 'on_hold'])->save();
    $send($this->agent)->assertForbidden();

    $conversation->forceFill(['status' => 'open'])->save();
    $send($this->agent)->assertAccepted()->assertJsonPath('data.status', 'queued');

    expect(Message::query()->count())->toBe(1);
});

test('the reply endpoint validates its input', function (array $input, string $field) {
    $conversation = attendedConversation($this->agent, $this->department, CarbonImmutable::now()->subHour());

    $this->actingAs($this->agent)
        ->postJson(route('conversations.messages.store', $conversation), $input)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'empty body' => [['body' => '', 'client_reference' => '7b0e0bd9-87a8-4c2e-8b2e-63f2c3a31a10'], 'body'],
    'too long' => [['body' => str_repeat('a', 4097), 'client_reference' => '7b0e0bd9-87a8-4c2e-8b2e-63f2c3a31a10'], 'body'],
    'missing reference' => [['body' => 'Hola'], 'client_reference'],
    'invalid reference' => [['body' => 'Hola', 'client_reference' => 'abc'], 'client_reference'],
]);

test('internal notes never reach the transport nor count as messages', function () {
    $transport = recordingTransport();
    $conversation = attendedConversation($this->agent, $this->department, CarbonImmutable::now()->subHour());

    $this->actingAs($this->agent)->post(route('conversations.notes.store', $conversation), ['body' => 'Dato privado del equipo']);

    expect($transport->sent)->toBe([])
        ->and(Message::query()->count())->toBe(0)
        ->and($conversation->refresh()->first_response_at)->toBeNull()
        ->and($conversation->last_outbound_at)->toBeNull();
});
