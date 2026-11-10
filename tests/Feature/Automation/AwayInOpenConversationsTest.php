<?php

use App\Actions\Conversations\CloseConversation;
use App\Enums\ConversationStatus;
use App\Jobs\SendAwayInOpenConversation;
use App\Models\Connection;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use App\Services\Conversations\PendingAttention;
use App\Services\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/*
| The away message in conversations that are already open, once per
| continuous period outside business hours, only where the connection's
| administrator turned it on. Sent through the real WhatsApp transport
| (Meta's Graph API faked at the HTTP level). Business hours are Monday to
| Friday 09:00 to 18:00 in Mexico City on a UTC server.
*/

const AWAY_OPEN_PHONE_NUMBER_ID = '140000000000177';
const AWAY_OPEN_MESSAGES_URL = 'https://graph.facebook.com/v26.0/'.AWAY_OPEN_PHONE_NUMBER_ID.'/messages';

beforeEach(function () {
    config(['app.timezone' => 'UTC', 'whatsapp.transport' => 'meta', 'services.meta.graph_version' => 'v26.0']);
    // Monday 2026-10-19 at 10:00 in Mexico City (16:00 UTC), within hours.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 16:00', 'UTC'));

    app(PlatformSettings::class)->update([
        'general' => ['timezone' => 'America/Mexico_City'],
        'business_hours' => ['enabled' => true],
    ]);

    $this->department = Department::factory()->create();
    $this->connection = Connection::factory()->create([
        'phone_number_id' => AWAY_OPEN_PHONE_NUMBER_ID,
        'access_token' => 'EAAGawayOpenTestToken0000000000000000000000000000000000',
        'default_department_id' => $this->department->id,
        'welcome_enabled' => true,
        'welcome_message' => 'Hola, gracias por escribirnos.',
        'away_enabled' => true,
        'away_message' => 'Estamos fuera de horario.',
        'away_in_open_conversations' => true,
        'reopen_window_minutes' => 120,
    ]);

    $accepted = 0;
    Http::preventStrayRequests();
    Http::fake([AWAY_OPEN_MESSAGES_URL => function () use (&$accepted) {
        $accepted++;

        return Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['input' => '5215512345678', 'wa_id' => '5215512345678']], 'messages' => [['id' => "wamid.away.open.{$accepted}"]]]);
    }]);

    $this->sent = fn (): array => Http::recorded(fn (Request $request): bool => $request->url() === AWAY_OPEN_MESSAGES_URL)
        ->map(fn (array $pair): ?string => $pair[0]->data()['text']['body'] ?? null)
        ->values()
        ->all();
    $this->at = fn (string $utc) => $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));
});

test('a customer writing again outside hours in an open conversation gets the away message once in that period', function () {
    receiveWhatsapp($this->connection, body: 'Hola');

    // 21:30 and 23:10 on Monday in Mexico City: the same closed stretch.
    ($this->at)('2026-10-20 03:30');
    receiveWhatsapp($this->connection, body: '¿Siguen ahí?');
    ($this->at)('2026-10-20 05:10');
    receiveWhatsapp($this->connection, body: 'Es urgente');
    receiveWhatsapp($this->connection, body: 'Por favor');

    $away = Message::query()->where('metadata->automatic_kind', 'away')->sole();
    expect(($this->sent)())->toBe(['Hola, gracias por escribirnos.', 'Estamos fuera de horario.'])
        ->and($away->metadata['away_period'])->toBe('2026-10-20T15:00:00+00:00');
});

test('a new period outside hours gets its own away message', function () {
    receiveWhatsapp($this->connection, body: 'Hola');

    ($this->at)('2026-10-20 03:30');
    receiveWhatsapp($this->connection, body: 'Lunes en la noche');
    // Tuesday 10:00 (open), then Tuesday 20:00 (closed again).
    ($this->at)('2026-10-20 16:00');
    receiveWhatsapp($this->connection, body: 'Martes en la mañana');
    ($this->at)('2026-10-21 02:00');
    receiveWhatsapp($this->connection, body: 'Martes en la noche');

    expect(($this->sent)())->toBe(['Hola, gracias por escribirnos.', 'Estamos fuera de horario.', 'Estamos fuera de horario.']);
});

test('a conversation started outside hours does not get the away message twice in the same period', function () {
    ($this->at)('2026-10-20 03:30');

    receiveWhatsapp($this->connection, body: 'Hola');
    receiveWhatsapp($this->connection, body: '¿Hay alguien?');

    expect(($this->sent)())->toBe(['Estamos fuera de horario.']);
});

test('a conversation reopened in the same period does not repeat the away message', function () {
    ($this->at)('2026-10-20 03:30');
    $receipt = receiveWhatsapp($this->connection, body: 'Hola');
    app(CloseConversation::class)->handle($receipt->conversation->refresh(), User::factory()->administrator()->create());

    ($this->at)('2026-10-20 04:00');
    receiveWhatsapp($this->connection, body: 'Una pregunta más');

    expect(($this->sent)())->toBe(['Estamos fuera de horario.']);
});

test('nothing changes while the switch is off: one greeting per conversation', function () {
    $this->connection->forceFill(['away_in_open_conversations' => false])->save();
    receiveWhatsapp($this->connection, body: 'Hola');
    Queue::fake([SendAwayInOpenConversation::class]);

    ($this->at)('2026-10-20 03:30');
    receiveWhatsapp($this->connection, body: '¿Siguen ahí?');

    expect(($this->sent)())->toBe(['Hola, gracias por escribirnos.']);
    Queue::assertNotPushed(SendAwayInOpenConversation::class);
});

test('within business hours an open conversation gets nothing more', function () {
    receiveWhatsapp($this->connection, body: 'Hola');

    ($this->at)('2026-10-19 18:00');
    receiveWhatsapp($this->connection, body: 'Sigo esperando');

    expect(($this->sent)())->toBe(['Hola, gracias por escribirnos.']);
});

test('without away text nothing is sent and the reason is logged', function () {
    receiveWhatsapp($this->connection, body: 'Hola');
    $this->connection->forceFill(['away_enabled' => false])->save();
    Log::spy();

    ($this->at)('2026-10-20 03:30');
    receiveWhatsapp($this->connection, body: 'Mensaje privado del cliente');

    expect(($this->sent)())->toBe(['Hola, gracias por escribirnos.']);
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'Automatic message not sent.'
        && $context['slot'] === 'away_in_open_conversation'
        && $context['reason'] === 'away_not_configured'
        && ! str_contains((string) json_encode($context), 'Mensaje privado'))->once();
});

test('a repeated webhook of the same customer message never sends it again', function () {
    receiveWhatsapp($this->connection, body: 'Hola');

    ($this->at)('2026-10-20 03:30');
    receiveWhatsapp($this->connection, body: '¿Siguen ahí?', id: 'wamid.repeated.1');
    receiveWhatsapp($this->connection, body: '¿Siguen ahí?', id: 'wamid.repeated.1');

    expect(($this->sent)())->toBe(['Hola, gracias por escribirnos.', 'Estamos fuera de horario.']);
});

test('a job run again for the same message, or for another one of the period, sends nothing more', function () {
    receiveWhatsapp($this->connection, body: 'Hola');
    ($this->at)('2026-10-20 03:30');
    $receipt = receiveWhatsapp($this->connection, body: '¿Siguen ahí?');

    dispatch_sync(new SendAwayInOpenConversation($receipt->conversation->id, $receipt->message->id));

    expect(($this->sent)())->toBe(['Hola, gracias por escribirnos.', 'Estamos fuera de horario.']);
});

test('a message processed more than an hour late does not get the away message', function () {
    $receipt = receiveWhatsapp($this->connection, body: 'Hola');
    ($this->at)('2026-10-20 03:30');
    $late = Message::factory()->create(['conversation_id' => $receipt->conversation->id, 'sent_at' => now()->subHours(2)]);

    dispatch_sync(new SendAwayInOpenConversation($receipt->conversation->id, $late->id));

    expect(($this->sent)())->toBe(['Hola, gracias por escribirnos.']);
});

test('the away message is not an answer of the team: the customer still counts as waiting for the agent', function () {
    app(PlatformSettings::class)->update(['operations' => ['pending_block_enabled' => true, 'pending_block_after_minutes' => 10]]);
    $agent = User::factory()->agent()->inDepartments($this->department)->create();
    $conversation = receiveWhatsapp($this->connection, body: 'Hola')->conversation;
    $conversation->forceFill(['status' => ConversationStatus::Open, 'assigned_user_id' => $agent->id, 'assigned_at' => now()])->save();

    ($this->at)('2026-10-20 03:30');
    receiveWhatsapp($this->connection, body: '¿Siguen ahí?');
    ($this->at)('2026-10-20 03:45');

    expect(Message::query()->where('metadata->automatic_kind', 'away')->count())->toBe(1)
        ->and($conversation->fresh()->last_outbound_at)->toBeNull()
        ->and(app(PendingAttention::class)->isOverdue($conversation->fresh()))->toBeTrue();
});
