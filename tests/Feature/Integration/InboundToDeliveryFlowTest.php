<?php

use App\Enums\ConversationStatus;
use App\Enums\DistributionMode;
use App\Enums\MessageStatus;
use App\Events\Broadcasts\RealtimeSignal;
use App\Events\Messaging\MessageStatusChanged;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\ConversationMetric;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use App\Notifications\NotificationKind;
use App\Services\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\WhatsAppFixtures;

/*
| Cross-module flow, end to end with every queue run inline (SIMULATED
| WhatsApp: signed fake webhooks and a transport double, never Meta):
| signed webhook → inbound pipeline → automatic distribution → welcome
| message → delivery → status webhook → real-time signals, notifications and
| conversation metrics, then the first human reply.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    // Monday 2026-10-19 at 10:00 in Mexico City, within the default hours.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00', 'America/Mexico_City'));
    app(PlatformSettings::class)->update([
        'general' => ['timezone' => 'America/Mexico_City'],
        'business_hours' => ['enabled' => true],
    ]);
    config(['broadcasting.default' => 'log']);

    $this->department = Department::factory()->distribution(DistributionMode::Automatic)->create();
    $this->agent = User::factory()->agent()->available()->inDepartments($this->department)->create();
    $this->connection = Connection::factory()->operational()->create([
        'business_account_id' => '102290129340398',
        'default_department_id' => $this->department->id,
        'welcome_enabled' => true,
        'welcome_message' => 'Hola, gracias por escribirnos.',
    ]);
    $this->transport = WhatsAppFixtures::transport();
});

test('a signed inbound webhook reaches the agent, greets the customer and keeps every module in step', function () {
    Event::fake([RealtimeSignal::class]);

    WhatsAppFixtures::post($this, $this->connection, whatsappWebhook(
        $this->connection,
        [whatsappTextMessage('5215512345678', 'Hola, necesito ayuda', id: 'wamid.in.1')],
        ['5215512345678' => 'Laura Pérez'],
    ))->assertOk();

    $conversation = Conversation::query()->sole();
    expect($conversation)
        ->department_id->toBe($this->department->id)
        ->assigned_user_id->toBe($this->agent->id)
        ->status->toBe(ConversationStatus::Open)
        ->first_response_at->toBeNull();

    $welcome = Message::query()->where('metadata->automatic', true)->sole();
    expect($welcome)
        ->body->toBe('Hola, gracias por escribirnos.')
        ->sender_user_id->toBeNull()
        ->status->toBe(MessageStatus::Accepted)
        ->whatsapp_message_id->toBe('wamid.out.'.$welcome->id)
        ->and($this->transport->sent)->toBe([$welcome->id]);

    $kinds = fn (): array => $this->agent->notifications()->pluck('kind')->all();
    expect($kinds())->toContain(NotificationKind::ConversationAssigned->value)
        ->toContain(NotificationKind::ConversationMessage->value);

    Event::assertDispatched(RealtimeSignal::class, fn (RealtimeSignal $signal): bool => $signal->event === 'message.recorded'
        && $signal->channels === ["conversations.{$conversation->id}"]);
    Event::assertDispatched(RealtimeSignal::class, fn (RealtimeSignal $signal): bool => $signal->event === 'inbox.changed'
        && in_array("users.{$this->agent->id}", $signal->channels, true));

    $metric = ConversationMetric::query()->where('conversation_id', $conversation->id)->sole();
    expect($metric)
        ->agent_id->toBe($this->agent->id)
        ->messages_in->toBe(1)
        ->messages_out->toBe(1)
        ->first_human_response_at->toBeNull();

    Event::fake([RealtimeSignal::class, MessageStatusChanged::class]);
    WhatsAppFixtures::post($this, $this->connection, WhatsAppFixtures::statusWebhook($this->connection, [
        WhatsAppFixtures::status($welcome->whatsapp_message_id, 'sent', CarbonImmutable::now()),
        WhatsAppFixtures::status($welcome->whatsapp_message_id, 'delivered', CarbonImmutable::now()->addSecond()),
    ]))->assertOk();

    expect($welcome->refresh()->status)->toBe(MessageStatus::Delivered);
    Event::assertDispatched(MessageStatusChanged::class, fn (MessageStatusChanged $event): bool => $event->messageId === $welcome->id
        && $event->status === 'delivered');
});

test('the first human reply is what the metrics count, never the automatic greeting', function () {
    $receipt = receiveWhatsapp($this->connection, '5215512345678', 'Hola');
    $conversation = $receipt->conversation->refresh();

    $this->actingAs($this->agent)
        ->postJson(route('conversations.messages.store', $conversation), [
            'body' => 'Con gusto le ayudo.',
            'client_reference' => (string) str()->uuid(),
        ])
        ->assertStatus(202);

    $reply = Message::query()->where('sender_user_id', $this->agent->id)->sole();
    expect($reply->status)->toBe(MessageStatus::Accepted)
        ->and($conversation->refresh()->first_response_at)->not->toBeNull();

    $metric = ConversationMetric::query()->where('conversation_id', $conversation->id)->sole();
    expect($metric->first_human_response_at?->toDateTimeString())->toBe($reply->created_at->toDateTimeString())
        ->and($metric->messages_out)->toBe(2);
});

test('outside business hours the away message is sent instead of the welcome', function () {
    $this->connection->forceFill(['away_enabled' => true, 'away_message' => 'Estamos fuera de horario.'])->save();
    $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00', 'America/Mexico_City'));

    receiveWhatsapp($this->connection);

    expect(Message::query()->where('metadata->automatic', true)->sole())
        ->body->toBe('Estamos fuera de horario.')
        ->metadata->toHaveKey('automatic_kind', 'away');
});
