<?php

use App\Actions\Conversations\CloseConversation;
use App\Enums\MessageStatus;
use App\Models\Connection;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use App\Services\Meta\ConnectionDiagnostics;
use App\Services\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
| Automatic messages through the real WhatsApp transport (Meta's Graph API
| faked at the HTTP level), the reasons logged when none is sent, and the
| connection diagnostics administrators read.
*/

const AUTOMATION_PHONE_NUMBER_ID = '140000000000099';
const AUTOMATION_MESSAGES_URL = 'https://graph.facebook.com/v26.0/'.AUTOMATION_PHONE_NUMBER_ID.'/messages';

beforeEach(function () {
    config(['app.timezone' => 'UTC', 'whatsapp.transport' => 'meta', 'services.meta.graph_version' => 'v26.0']);
    // Monday 2026-10-19 at 10:00 in Mexico City (16:00 UTC), within the default hours.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 16:00', 'UTC'));

    app(PlatformSettings::class)->update([
        'general' => ['timezone' => 'America/Mexico_City'],
        'business_hours' => ['enabled' => true],
    ]);

    $this->department = Department::factory()->create();
    $this->connection = Connection::factory()->create([
        'phone_number_id' => AUTOMATION_PHONE_NUMBER_ID,
        'access_token' => 'EAAGautomationTestToken000000000000000000000000000000000',
        'default_department_id' => $this->department->id,
        'welcome_enabled' => true,
        'welcome_message' => 'Hola, gracias por escribirnos.',
        'away_enabled' => true,
        'away_message' => 'Estamos fuera de horario.',
        'farewell_enabled' => true,
        'farewell_message' => 'Gracias por contactarnos.',
    ]);
    $this->sent = fn (): array => Http::recorded(fn (Request $request): bool => $request->url() === AUTOMATION_MESSAGES_URL)
        ->map(fn (array $pair): ?string => $pair[0]->data()['text']['body'] ?? null)
        ->all();
});

function metaAccepts(): void
{
    Http::fake([AUTOMATION_MESSAGES_URL => Http::sequence()
        ->push(['messaging_product' => 'whatsapp', 'contacts' => [['input' => '5215512345678', 'wa_id' => '5215512345678']], 'messages' => [['id' => 'wamid.auto.1']]])
        ->push(['messaging_product' => 'whatsapp', 'contacts' => [['input' => '5215512345678', 'wa_id' => '5215512345678']], 'messages' => [['id' => 'wamid.auto.2']]])]);
}

test('on a UTC server, a customer writing at 21:30 Mexico City time gets the away message through Meta', function () {
    metaAccepts();
    // 03:30 UTC on Tuesday is 21:30 on Monday in Mexico City: closed.
    $this->travelTo(CarbonImmutable::parse('2026-10-20 03:30', 'UTC'));

    $receipt = receiveWhatsapp($this->connection);

    $away = Message::query()->where('metadata->automatic', true)->sole();
    expect(($this->sent)())->toBe(['Estamos fuera de horario.'])
        ->and($away->metadata['automatic_kind'])->toBe('away')
        ->and($away->status)->not->toBe(MessageStatus::Failed)
        ->and($away->whatsapp_message_id)->toBe('wamid.auto.1')
        ->and($away->conversation_id)->toBe($receipt->conversation->id);
    Http::assertSent(fn (Request $request): bool => $request->url() === AUTOMATION_MESSAGES_URL
        && str_ends_with((string) $request->data()['to'], '5512345678')
        && $request->data()['type'] === 'text');
});

test('the greeting goes once per conversation: writing again outside hours in the same open conversation sends nothing more', function () {
    metaAccepts();
    receiveWhatsapp($this->connection, body: 'Hola');

    $this->travelTo(CarbonImmutable::parse('2026-10-20 03:30', 'UTC'));
    receiveWhatsapp($this->connection, body: '¿Siguen ahí?');

    expect(($this->sent)())->toBe(['Hola, gracias por escribirnos.']);
});

test('the farewell reaches Meta when an agent closes the conversation inside the window', function () {
    metaAccepts();
    $receipt = receiveWhatsapp($this->connection);
    $agent = User::factory()->administrator()->create();

    app(CloseConversation::class)->handle($receipt->conversation->refresh(), $agent);

    expect(($this->sent)())->toBe(['Hola, gracias por escribirnos.', 'Gracias por contactarnos.']);
});

test('a definitive refusal by Meta is recorded on the automatic message', function () {
    Http::fake([AUTOMATION_MESSAGES_URL => Http::response(['error' => ['message' => '(#100) Invalid parameter', 'type' => 'OAuthException', 'code' => 100]], 400)]);

    receiveWhatsapp($this->connection);

    expect(Message::query()->where('metadata->automatic', true)->sole())
        ->status->toBe(MessageStatus::Failed)
        ->error_code->not->toBeNull()
        ->error_message->not->toBeNull();
});

test('when no automatic message is sent, the reason is logged without customer data', function () {
    Http::fake();
    Log::spy();
    $this->connection->forceFill(['away_enabled' => false])->save();
    $this->travelTo(CarbonImmutable::parse('2026-10-20 03:30', 'UTC'));

    receiveWhatsapp($this->connection, body: 'Mensaje privado del cliente');

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'Automatic message not sent.'
        && $context['reason'] === 'away_not_configured'
        && $context['schedule'] === 'platform'
        && $context['is_open'] === false
        && ! str_contains((string) json_encode($context), 'Mensaje privado')
        && ! str_contains((string) json_encode($context), '5215512345678'))->once();
    Http::assertNothingSent();
});

test('the diagnostics tell what a new conversation would get now and warn about an away message without schedule', function () {
    $diagnostics = fn (): array => app(ConnectionDiagnostics::class)->for($this->connection->refresh())['automation'];

    expect($diagnostics())->toMatchArray(['schedule' => 'platform', 'is_open' => true, 'local_time' => '10:00'])
        ->and($diagnostics()['greeting_now'])->toMatchArray(['kind' => 'welcome', 'source' => 'connection']);

    $this->travelTo(CarbonImmutable::parse('2026-10-20 03:30', 'UTC'));
    expect($diagnostics())->toMatchArray(['is_open' => false, 'local_time' => '21:30'])
        ->and($diagnostics()['greeting_now']['kind'])->toBe('away');

    app(PlatformSettings::class)->update(['business_hours' => ['enabled' => false]]);
    expect($diagnostics())->toMatchArray(['schedule' => 'continuous', 'is_open' => true])
        ->and($diagnostics()['greeting_now']['kind'])->toBe('welcome');
});

test('the diagnostics count recent automatic messages and show the last refusal', function () {
    Http::fake([AUTOMATION_MESSAGES_URL => Http::response(['error' => ['message' => '(#100) Invalid parameter', 'type' => 'OAuthException', 'code' => 100]], 400)]);
    receiveWhatsapp($this->connection);

    $automation = app(ConnectionDiagnostics::class)->for($this->connection->refresh())['automation'];

    expect($automation['failed'])->toBe(1)
        ->and($automation['sent'])->toBe(['welcome' => 0, 'away' => 0, 'farewell' => 0])
        ->and($automation['last_failure'])->toMatchArray(['kind' => 'welcome'])
        ->and($automation['last_failure']['error'])->not->toBeNull();
});
