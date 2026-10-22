<?php

use App\Actions\Automation\QueueAutomaticMessage;
use App\Actions\Conversations\CloseConversation;
use App\Actions\Conversations\ProcessInboundMessages;
use App\Actions\Conversations\ReopenConversation;
use App\Enums\ConversationEventType;
use App\Enums\MessageDirection;
use App\Events\Conversations\ConversationChanged;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use App\Services\Automation\AutomaticMessageKind;
use App\Services\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/*
| Automatic welcome, away and farewell messages: priorities, business hours,
| the customer service window, idempotency under repeated webhooks and the
| absence of loops. Inbound traffic goes through the real pipeline.
*/

/**
 * @return Collection<int, Message>
 */
function automaticMessages(?Conversation $conversation = null)
{
    return Message::query()
        ->when($conversation !== null, fn ($query) => $query->where('conversation_id', $conversation->id))
        ->where('metadata->automatic', true)
        ->orderBy('id')
        ->get();
}

beforeEach(function () {
    // Monday 2026-10-19 at 10:00 in Mexico City, within the default hours.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00', 'America/Mexico_City'));

    app(PlatformSettings::class)->update([
        'general' => ['timezone' => 'America/Mexico_City'],
        'business_hours' => ['enabled' => true],
    ]);

    $this->department = Department::factory()->create();
    $this->connection = Connection::factory()->create([
        'default_department_id' => $this->department->id,
        'welcome_enabled' => true,
        'welcome_message' => 'Hola, gracias por escribirnos.',
        'away_enabled' => true,
        'away_message' => 'Estamos fuera de horario.',
        'farewell_enabled' => true,
        'farewell_message' => 'Gracias por contactarnos.',
    ]);
});

test('a new conversation gets the connection welcome once, as an automatic message without sender', function () {
    $receipt = receiveWhatsapp($this->connection, '5215512345678', 'Hola');

    $message = automaticMessages($receipt->conversation)->sole();

    expect($message)
        ->direction->toBe(MessageDirection::Outbound)
        ->body->toBe('Hola, gracias por escribirnos.')
        ->sender_user_id->toBeNull()
        ->client_reference->toBe(QueueAutomaticMessage::reference($receipt->conversation->id, AutomaticMessageKind::Welcome, 0))
        ->and($message->metadata)->toMatchArray([
            'automatic' => true,
            'automatic_kind' => 'welcome',
            'automatic_source' => 'connection',
            'cycle' => 0,
        ])
        ->and($receipt->conversation->refresh()->first_response_at)->toBeNull();
});

test('the department welcome takes precedence over the connection welcome', function () {
    $this->department->forceFill(['welcome_message' => 'Te atiende Soporte.'])->save();

    $receipt = receiveWhatsapp($this->connection);

    expect(automaticMessages($receipt->conversation)->sole())
        ->body->toBe('Te atiende Soporte.')
        ->metadata->toHaveKey('automatic_source', 'department');
});

test('repeated webhooks, later messages and re-dispatched events never send a second greeting', function () {
    $payload = whatsappWebhook($this->connection, [whatsappTextMessage('5215512345678', 'Hola', id: 'wamid.same')]);

    app(ProcessInboundMessages::class)->handle($payload);
    app(ProcessInboundMessages::class)->handle($payload);
    receiveWhatsapp($this->connection, '5215512345678', 'Sigo aquí');

    $conversation = Conversation::query()->sole();
    $created = $conversation->events()->where('type', ConversationEventType::Created)->sole();
    ConversationChanged::dispatch($conversation->id, ConversationEventType::Created->value, null, $created->id);

    expect(automaticMessages())->toHaveCount(1)
        ->and(Message::query()->where('direction', MessageDirection::Inbound)->count())->toBe(2);
});

test('outside business hours the away message replaces the welcome', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 21:00', 'America/Mexico_City'));

    $receipt = receiveWhatsapp($this->connection);

    expect(automaticMessages($receipt->conversation)->sole())
        ->body->toBe('Estamos fuera de horario.')
        ->metadata->toHaveKey('automatic_kind', 'away');
});

test('a non-working day counts as outside business hours and the department away message wins', function () {
    $this->department->forceFill(['away_message' => 'Hoy no laboramos.'])->save();
    app(PlatformSettings::class)->update(['business_hours' => ['holidays' => [
        ['date' => '2026-10-19', 'label' => 'Asueto', 'recurring' => false],
    ]]]);

    $receipt = receiveWhatsapp($this->connection);

    expect(automaticMessages($receipt->conversation)->sole())
        ->body->toBe('Hoy no laboramos.')
        ->metadata->toMatchArray(['automatic_kind' => 'away', 'automatic_source' => 'department']);
});

test('outside hours without any away message nothing is sent', function () {
    $this->connection->forceFill(['away_enabled' => false])->save();
    $this->travelTo(CarbonImmutable::parse('2026-10-24 12:00', 'America/Mexico_City'));

    receiveWhatsapp($this->connection);

    expect(automaticMessages())->toBeEmpty();
});

test('disabled or empty messages are not sent', function () {
    $this->connection->forceFill(['welcome_enabled' => false])->save();

    receiveWhatsapp($this->connection);

    expect(automaticMessages())->toBeEmpty();
});

test('a backlog processed late does not greet the customer', function () {
    receiveWhatsapp($this->connection, at: CarbonImmutable::now()->subHours(3));

    expect(automaticMessages())->toBeEmpty();
});

test('nothing is queued through a connection that stopped operating', function () {
    $this->connection->forceFill(['welcome_enabled' => false])->save();
    $receipt = receiveWhatsapp($this->connection);

    $this->connection->forceFill(['welcome_enabled' => true, 'is_active' => false])->save();
    $created = $receipt->conversation->events()->where('type', ConversationEventType::Created)->sole();
    ConversationChanged::dispatch($receipt->conversation->id, ConversationEventType::Created->value, null, $created->id);

    expect(automaticMessages())->toBeEmpty();
});

test('closing sends the farewell once while the customer service window is open', function () {
    $receipt = receiveWhatsapp($this->connection);
    $closer = User::factory()->administrator()->create();

    app(CloseConversation::class)->handle($receipt->conversation, $closer);

    $closed = $receipt->conversation->events()->where('type', ConversationEventType::Closed)->sole();
    ConversationChanged::dispatch($receipt->conversation->id, ConversationEventType::Closed->value, $closer->id, $closed->id);

    expect(automaticMessages($receipt->conversation)->pluck('metadata.automatic_kind')->all())->toBe(['welcome', 'farewell']);
});

test('the farewell is not sent once the window expired, nor when disabled', function () {
    $closer = User::factory()->administrator()->create();
    $expired = receiveWhatsapp($this->connection, '5215500000001', at: CarbonImmutable::now()->subHours(25));

    app(CloseConversation::class)->handle($expired->conversation, $closer);

    $this->connection->forceFill(['farewell_enabled' => false])->save();
    $recent = receiveWhatsapp($this->connection, '5215500000002');
    app(CloseConversation::class)->handle($recent->conversation, $closer);

    expect(automaticMessages()->pluck('metadata.automatic_kind')->all())->toBe(['welcome']);
});

test('a conversation reopened by the customer starts a new cycle with its own greeting', function () {
    $this->connection->forceFill(['reopen_window_minutes' => 120, 'farewell_enabled' => false])->save();
    $closer = User::factory()->administrator()->create();

    $first = receiveWhatsapp($this->connection);
    app(CloseConversation::class)->handle($first->conversation, $closer);

    $this->travel(10)->minutes();
    $reopened = receiveWhatsapp($this->connection, body: 'Una duda más');

    expect($reopened->conversation->id)->toBe($first->conversation->id)
        ->and($reopened->conversation->reopen_count)->toBe(1)
        ->and(automaticMessages($first->conversation)->pluck('metadata.cycle')->all())->toBe([0, 1]);
});

test('a manual reopen by an agent sends no greeting', function () {
    $this->connection->forceFill(['welcome_enabled' => false, 'farewell_enabled' => false])->save();
    $admin = User::factory()->administrator()->create();
    $receipt = receiveWhatsapp($this->connection);
    app(CloseConversation::class)->handle($receipt->conversation, $admin);

    $this->connection->forceFill(['welcome_enabled' => true])->save();
    app(ReopenConversation::class)->handle($receipt->conversation->refresh(), $admin, $receipt->conversation->version);

    expect(automaticMessages())->toBeEmpty();
});
