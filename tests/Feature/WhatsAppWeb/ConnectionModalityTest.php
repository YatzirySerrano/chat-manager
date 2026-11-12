<?php

use App\Enums\ConnectionHealth;
use App\Enums\ConnectionProvider;
use App\Enums\CustomerWindowStatus;
use App\Enums\WhatsAppWebState;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\Automation\AutomaticMessages;
use App\Services\Conversations\CustomerServiceWindow;
use App\Services\Messaging\ChannelTransports;
use App\Services\Meta\WhatsAppTransport;
use App\Services\WhatsAppWeb\WhatsAppWebTransport;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\WhatsAppFixtures;
use Tests\Support\WhatsAppWebFixtures;

/*
| WhatsApp Web (QR) as a second modality of the WhatsApp channel, chosen per
| connection, next to WhatsApp Cloud API (which must behave exactly as before).
*/

beforeEach(function () {
    Http::preventStrayRequests();
    WhatsAppWebFixtures::configure();
    $this->admin = User::factory()->superAdministrator()->create();
});

test('the creation form offers WhatsApp Web only where the server enabled it', function () {
    $this->actingAs($this->admin)
        ->get(route('connections.create', ['channel' => 'whatsapp', 'provider' => 'whatsapp_web']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('connections/Form')
            ->where('provider', ['value' => 'whatsapp_web', 'label' => 'WhatsApp Web (QR)'])
            ->where('whatsappWebEnabled', true)
            ->where('graphVersion', null)
            ->where('whatsappWeb', null));

    config(['whatsapp_web.enabled' => false]);

    $this->actingAs($this->admin)
        ->get(route('connections.create', ['channel' => 'whatsapp', 'provider' => 'whatsapp_web']))
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->get(route('connections.create', ['channel' => 'whatsapp']))
        ->assertInertia(fn (Assert $page) => $page->where('provider.value', 'meta_cloud_api')->where('whatsappWebEnabled', false));
});

test('a WhatsApp Web connection is created without Meta credentials, inactive and without a linked phone', function () {
    $department = Department::factory()->create();

    $this->actingAs($this->admin)
        ->post(route('connections.store'), [
            'channel' => 'whatsapp',
            'provider' => 'whatsapp_web',
            'name' => 'Cobranza QR',
            'department_ids' => [$department->id],
            'default_department_id' => $department->id,
            'welcome_enabled' => false,
            'away_enabled' => false,
            'farewell_enabled' => false,
            'reopen_window_minutes' => 120,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $connection = Connection::query()->where('name', 'Cobranza QR')->sole();

    expect($connection->provider)->toBe(ConnectionProvider::WhatsappWeb)
        ->and($connection->channel->value)->toBe('whatsapp')
        ->and($connection->is_active)->toBeFalse()
        ->and($connection->webSessionState())->toBe(WhatsAppWebState::Unconfigured)
        ->and($connection->phone_number_id)->toBeNull()
        ->and($connection->access_token)->toBeNull()
        ->and($connection->welcome_enabled)->toBeFalse()
        ->and($connection->reopen_window_minutes)->toBe(120)
        ->and($connection->default_department_id)->toBe($department->id)
        ->and($connection->health())->toBe(ConnectionHealth::Inactive)
        ->and($connection->configurationHealth())->toBe(ConnectionHealth::NotConfigured);

    Http::assertNothingSent();
});

test('Meta identifiers and secrets are refused for a WhatsApp Web connection', function () {
    $this->actingAs($this->admin)
        ->post(route('connections.store'), [
            'channel' => 'whatsapp',
            'provider' => 'whatsapp_web',
            'name' => 'Cobranza QR',
            'department_ids' => [],
            'phone_number_id' => '106540352242922',
            'access_token' => 'EAAG'.str_repeat('a', 40),
        ])
        ->assertSessionHasErrors(['phone_number_id', 'access_token']);

    expect(Connection::query()->count())->toBe(0);
});

test('the modality cannot be chosen where it is disabled nor changed afterwards', function () {
    config(['whatsapp_web.enabled' => false]);

    $this->actingAs($this->admin)
        ->post(route('connections.store'), ['channel' => 'whatsapp', 'provider' => 'whatsapp_web', 'name' => 'Cobranza QR', 'department_ids' => []])
        ->assertSessionHasErrors('provider');

    $cloud = Connection::factory()->create(['name' => 'Oficial']);

    $this->actingAs($this->admin)
        ->put(route('connections.update', $cloud), ['name' => 'Oficial', 'provider' => 'whatsapp_web', 'department_ids' => []])
        ->assertSessionHasErrors('provider');

    expect($cloud->refresh()->provider)->toBe(ConnectionProvider::MetaCloudApi);
});

test('people who cannot manage connections cannot create a WhatsApp Web connection', function () {
    $this->actingAs(User::factory()->agent()->create())
        ->post(route('connections.store'), ['channel' => 'whatsapp', 'provider' => 'whatsapp_web', 'name' => 'Cobranza QR', 'department_ids' => []])
        ->assertForbidden();

    expect(Connection::query()->count())->toBe(0);
});

test('a WhatsApp Web connection cannot be activated before a phone is linked', function () {
    $connection = Connection::factory()->whatsAppWebUnlinked()->create();

    $this->actingAs($this->admin)
        ->patch(route('connections.status.update', $connection), ['is_active' => true])
        ->assertSessionHasErrors('is_active');

    expect($connection->refresh()->is_active)->toBeFalse();
});

test('each connection uses the transport of its own modality', function () {
    config(['whatsapp.transport' => 'meta']);
    $transports = app(ChannelTransports::class);

    expect($transports->for(Connection::factory()->whatsAppWeb()->create()))->toBeInstanceOf(WhatsAppWebTransport::class)
        ->and($transports->for(Connection::factory()->create())->name())->toBe('meta');

    // The Cloud API binding (and the doubles tests register) keeps deciding the official transport.
    $double = WhatsAppFixtures::transport();

    expect($transports->for(Connection::factory()->create()))->toBe($double)
        ->and(app(WhatsAppTransport::class))->toBe($double)
        ->and($transports->for(Connection::factory()->whatsAppWeb()->create()))->toBeInstanceOf(WhatsAppWebTransport::class);
});

test('the 24 hour window and templates only apply to WhatsApp Cloud API', function () {
    $window = app(CustomerServiceWindow::class);
    $web = Conversation::factory()->create(['connection_id' => Connection::factory()->whatsAppWeb()->create()->id, 'last_inbound_at' => now()->subDays(3)]);
    $cloud = Conversation::factory()->create(['connection_id' => Connection::factory()->verified()->create()->id, 'last_inbound_at' => now()->subDays(3)]);

    expect($window->for($web)->status)->toBe(CustomerWindowStatus::NotApplicable)
        ->and($window->for($web)->allowsFreeFormMessages())->toBeTrue()
        ->and($window->for($cloud)->status)->toBe(CustomerWindowStatus::Expired)
        ->and($window->for($cloud)->allowsFreeFormMessages())->toBeFalse()
        ->and($web->channelConnection->supportsTemplates())->toBeFalse()
        ->and($cloud->channelConnection->supportsTemplates())->toBeTrue()
        ->and($this->admin->can('sync', [MessageTemplate::class, $web->channelConnection]))->toBeFalse()
        ->and($this->admin->can('sync', [MessageTemplate::class, $cloud->channelConnection]))->toBeTrue();
});

test('the connection resource describes the session of a WhatsApp Web connection and nothing of Meta', function () {
    $web = Connection::factory()->whatsAppWeb()->create(['name' => 'Cobranza QR']);
    $cloud = Connection::factory()->verified()->create(['name' => 'Oficial']);

    $this->actingAs($this->admin)
        ->get(route('connections.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('whatsappWebEnabled', true)
            ->where('channels', fn ($channels) => collect($channels)->contains('value', 'whatsapp_web'))
            ->where('connections', function ($connections) use ($web, $cloud): bool {
                $webRow = collect($connections)->firstWhere('id', $web->id);
                $cloudRow = collect($connections)->firstWhere('id', $cloud->id);

                return $webRow['provider']['value'] === 'whatsapp_web'
                    && $webRow['whatsapp_web']['state'] === 'connected'
                    && $webRow['whatsapp_web']['can_send'] === true
                    && $webRow['whatsapp_web']['phone'] === '+52 55 1234 5678'
                    && $webRow['has_required_credentials'] === true
                    && $webRow['monitoring']['credentials'] === null
                    && $webRow['monitoring']['webhook'] === null
                    && $webRow['monitoring']['subscription'] === null
                    && $webRow['can']['verify'] === false
                    && $cloudRow['whatsapp_web'] === null
                    && $cloudRow['can']['verify'] === true
                    && $cloudRow['monitoring']['credentials'] !== null;
            }));

    $this->actingAs($this->admin)
        ->get(route('connections.index', ['channel' => 'whatsapp_web']))
        ->assertInertia(fn (Assert $page) => $page->has('connections', 1)->where('connections.0.id', $web->id));
});

test('the page of a WhatsApp Web connection has its link state and no Meta checklist', function () {
    Http::fake([WhatsAppWebFixtures::SERVICE.'/health' => Http::response(['status' => 'ok'])]);
    $web = Connection::factory()->whatsAppWeb()->create();

    $this->actingAs($this->admin)
        ->get(route('connections.show', $web))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('provider.value', 'whatsapp_web')
            ->where('diagnostics', null)
            ->where('whatsappWeb.state', 'connected')
            ->where('whatsappWeb.can_send', true)
            ->where('whatsappWeb.service_available', true)
            ->where('whatsappWeb.can_manage', true)
            ->where('whatsappWeb.actions.logout', true)
            ->where('whatsappWeb.actions.link', false)
            ->where('can.verify', false));
});

test('health never calls a stopped, recovering or revoked session healthy', function (WhatsAppWebState $state, ?string $reason, ConnectionHealth $expected) {
    $connection = Connection::factory()->whatsAppWeb()->create([
        'web_session_state' => $state,
        'web_session_reason' => $reason,
        'web_session_linked_at' => $state->isLinked() ? now() : null,
        'last_inbound_at' => now(),
        'last_outbound_at' => now(),
    ]);

    expect($connection->configurationHealth())->toBe($expected)
        ->and($connection->sendingBlockReason() === null)->toBe($state === WhatsAppWebState::Connected);
})->with([
    'connected with traffic' => [WhatsAppWebState::Connected, null, ConnectionHealth::Operational],
    'reconnecting' => [WhatsAppWebState::Reconnecting, null, ConnectionHealth::Configured],
    'stopped on purpose' => [WhatsAppWebState::Disconnected, 'requested', ConnectionHealth::Configured],
    'revoked from the phone' => [WhatsAppWebState::LoggedOut, 'logged_out_from_phone', ConnectionHealth::Error],
    'closed from ChatManager' => [WhatsAppWebState::LoggedOut, 'requested', ConnectionHealth::NotConfigured],
    'error' => [WhatsAppWebState::Error, null, ConnectionHealth::Error],
    'waiting for the scan' => [WhatsAppWebState::QrPending, null, ConnectionHealth::NotConfigured],
]);

test('a WhatsApp Web connection never keeps automatic messages, whatever the form sends', function () {
    $this->actingAs($this->admin)
        ->post(route('connections.store'), [
            'channel' => 'whatsapp',
            'provider' => 'whatsapp_web',
            'name' => 'Cobranza QR',
            'department_ids' => [],
            'welcome_enabled' => true,
            'welcome_message' => 'Hola, gracias por escribir',
            'away_enabled' => true,
            'away_message' => 'Estamos fuera de horario',
            'away_in_open_conversations' => true,
            'farewell_enabled' => true,
            'farewell_message' => 'Hasta luego',
            'reopen_window_minutes' => 60,
        ])
        ->assertSessionHasNoErrors();

    $connection = Connection::query()->where('name', 'Cobranza QR')->sole();

    expect($connection->welcome_enabled)->toBeFalse()
        ->and($connection->welcome_message)->toBeNull()
        ->and($connection->away_enabled)->toBeFalse()
        ->and($connection->away_message)->toBeNull()
        ->and($connection->away_in_open_conversations)->toBeFalse()
        ->and($connection->farewell_enabled)->toBeFalse()
        ->and($connection->farewell_message)->toBeNull()
        // Reopening still applies.
        ->and($connection->reopen_window_minutes)->toBe(60);
});

test('nothing automatic is ever sent through a phone linked by QR, even with messages stored', function () {
    $messages = ['welcome_enabled' => true, 'welcome_message' => 'Hola', 'away_enabled' => true, 'away_message' => 'Fuera de horario', 'away_in_open_conversations' => true, 'farewell_enabled' => true, 'farewell_message' => 'Adiós'];
    $department = Department::factory()->create(['welcome_message' => 'Bienvenida del departamento']);
    $web = Connection::factory()->whatsAppWeb()->create([...$messages, 'default_department_id' => $department->id]);
    $web->departments()->sync([$department->id]);
    $cloud = Connection::factory()->verified()->create($messages);
    $planner = app(AutomaticMessages::class);

    expect($planner->greeting($web, $department, now()))->toBeNull()
        ->and($planner->greeting($web, null, now()))->toBeNull()
        ->and($planner->farewell($web))->toBeNull()
        // WhatsApp Cloud API keeps its automatic messages exactly as before.
        ->and($planner->farewell($cloud))->not->toBeNull()
        ->and($planner->greeting($cloud, null, now()))->not->toBeNull();

    // End to end: a new conversation and a second message of the customer produce no outbound message.
    WhatsAppWebFixtures::event($this, $web, 'message.inbound', WhatsAppWebFixtures::inbound())->assertOk();
    WhatsAppWebFixtures::event($this, $web, 'message.inbound', WhatsAppWebFixtures::inbound())->assertOk();

    expect(Message::query()->where('direction', 'inbound')->count())->toBe(2)
        ->and(Message::query()->where('direction', 'outbound')->count())->toBe(0);

    Http::assertNothingSent();
});
