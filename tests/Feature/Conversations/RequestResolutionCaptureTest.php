<?php

use App\Actions\Conversations\QueueOutboundMessage;
use App\Enums\AuditAction;
use App\Enums\ConversationStatus;
use App\Events\Conversations\ConversationChanged;
use App\Jobs\DeliverOutboundMessage;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use App\Support\RequestFields\RequestFieldCatalog;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
| «Resolución»: the authorization an agent sends («Se autoriza …» with
| «Monto autorizado», «Desembolso», «Cantidad a pagar») is kept in «Datos de
| la solicitud» on its own, and the amount confirmed when completing the
| request becomes its «Monto autorizado», so everything agrees.
*/

beforeEach(function () {
    Queue::fake([DeliverOutboundMessage::class]);
    $this->department = Department::factory()->create();
    $this->agent = User::factory()->agent()->inDepartments($this->department)->create();
    $this->connection = Connection::factory()->operational()->create(['default_department_id' => $this->department->id]);
    $this->conversation = receiveWhatsapp($this->connection)->conversation;
    $this->conversation->forceFill(['assigned_user_id' => $this->agent->id, 'assigned_at' => now(), 'status' => ConversationStatus::Open])->save();
});

function sendReply(Conversation $conversation, User $agent, string $body): Message
{
    return app(QueueOutboundMessage::class)->handle($conversation->refresh(), $agent, $body, (string) Str::uuid());
}

function storedRequestValues(Conversation $conversation): array
{
    return RequestFieldCatalog::values($conversation->refresh()->request_fields);
}

test('an authorization sent by the agent keeps its amounts and completes what is missing', function () {
    $this->conversation->forceFill(['request_fields' => [
        ['key' => 'cliente', 'label' => 'Cliente', 'value' => 'Beatriz Guerrero', 'message_id' => null, 'captured_at' => null],
    ]])->save();
    Event::fake([ConversationChanged::class]);

    $message = sendReply($this->conversation, $this->agent, "*Se autoriza reactivación de crédito*\nProducto: S12 JUEVES\nCliente: BEATRIZ GUERRERO OLVERA\nMonto autorizado: \$3000\nDesembolso: \$2,130\nCantidad a pagar: \$398");

    expect(storedRequestValues($this->conversation))->toBe([
        // Typed before by the analyst: the authorization never replaces it.
        'cliente' => 'Beatriz Guerrero',
        'monto_autorizado' => '$3,000',
        'desembolso' => '$2,130',
        'cantidad_a_pagar' => '$398',
        'producto' => 'S12 JUEVES',
        'tipo' => 'Reactivación',
    ]);

    $field = collect($this->conversation->request_fields)->firstWhere('key', 'desembolso');
    expect($field['message_id'])->toBe($message->id)
        ->and(AuditLog::query()->where('action', AuditAction::ConversationRequestFieldsChanged)->sole()->properties)
        ->toMatchArray(['mode' => 'auto', 'source' => 'outbound_message', 'message_id' => $message->id]);

    Event::assertDispatched(ConversationChanged::class, fn (ConversationChanged $event): bool => $event->reason === 'request_fields' && $event->conversationId === $this->conversation->id);
});

test('a hurried authorization with typos is read all the same, and the latest one wins', function () {
    sendReply($this->conversation, $this->agent, "▶️ *SE AUTORISA RENOVACION DE CRED*\n*Monto:* 3,000.00\nDesenbolso \$ 2.130\nCant a pagar: 398");
    sendReply($this->conversation, $this->agent, "Se autoriza renovación\nMonto aut: \$3,500");

    expect(storedRequestValues($this->conversation))->toBe([
        'monto_autorizado' => '$3,500',
        'desembolso' => '$2,130',
        'cantidad_a_pagar' => '$398',
        'tipo' => 'Renovación',
    ]);
});

test('ordinary replies and amounts that are not money leave the request data alone', function () {
    sendReply($this->conversation, $this->agent, "Hola, ¿me confirma el monto?\nMonto autorizado: \$3000");
    sendReply($this->conversation, $this->agent, "Se autoriza crédito nuevo\nDesembolso: pendiente");

    expect(storedRequestValues($this->conversation))->toBe(['tipo' => 'Nuevo'])
        ->and(AuditLog::query()->where('action', AuditAction::ConversationRequestFieldsChanged)->count())->toBe(1);
});

test('the amount confirmed when completing becomes the authorized amount', function () {
    $this->conversation->forceFill(['request_fields' => [
        ['key' => 'monto_autorizado', 'label' => 'Monto autorizado', 'value' => '$2,000', 'message_id' => null, 'captured_at' => null],
    ]])->save();

    $this->actingAs($this->agent)
        ->from(route('conversations.show', $this->conversation))
        ->post(route('conversations.close', $this->conversation), ['version' => $this->conversation->refresh()->version, 'completed' => true, 'outcome' => 'approved', 'outcome_amount' => '3000'])
        ->assertSessionHasNoErrors();

    expect(storedRequestValues($this->conversation))->toBe(['monto_autorizado' => '$3,000'])
        ->and($this->conversation->outcome_amount)->toBe('3000.00');
});

test('the same amount written another way is not a change', function () {
    $this->conversation->forceFill(['request_fields' => [
        ['key' => 'monto_autorizado', 'label' => 'Monto autorizado', 'value' => '3000', 'message_id' => null, 'captured_at' => null],
    ]])->save();

    sendReply($this->conversation, $this->agent, "Se autoriza adicional\nMonto autorizado: \$3,000.00");

    expect(storedRequestValues($this->conversation))->toBe(['monto_autorizado' => '3000', 'tipo' => 'Adicional']);
});

test('the suggestion prefers the authorized amount kept in the request data', function () {
    sendReply($this->conversation, $this->agent, "Se autoriza reactivación\nMonto autorizado: \$3000");
    $this->conversation->forceFill(['request_fields' => [
        ['key' => 'monto_autorizado', 'label' => 'Monto autorizado', 'value' => '$3,200', 'message_id' => null, 'captured_at' => null],
    ]])->save();

    $this->actingAs($this->agent)
        ->getJson(route('conversations.outcome.suggestion', $this->conversation))
        ->assertOk()
        ->assertJsonPath('suggestion.outcome', 'approved')
        ->assertJsonPath('suggestion.amount', '3200.00')
        ->assertJsonPath('suggestion.source', 'message');
});

test('the one-click completions keep the backend contract', function (array $payload, ?string $outcome, ?string $amount) {
    $this->actingAs($this->agent)
        ->from(route('conversations.show', $this->conversation))
        ->post(route('conversations.close', $this->conversation), ['version' => $this->conversation->refresh()->version, 'completed' => true, 'farewell' => false, ...$payload])
        ->assertSessionHasNoErrors();

    expect($this->conversation->refresh())
        ->status->toBe(ConversationStatus::Closed)
        ->outcome?->value->toBe($outcome)
        ->outcome_amount->toBe($amount);
})->with([
    'approved with the known amount' => [['outcome' => 'approved', 'outcome_amount' => '3000'], 'approved', '3000.00'],
    'approved without an amount' => [['outcome' => 'approved', 'outcome_amount' => null], 'approved', null],
    'rejected' => [['outcome' => 'rejected', 'outcome_amount' => null], 'rejected', null],
    'without result' => [['outcome' => null, 'outcome_amount' => null], null, null],
]);
