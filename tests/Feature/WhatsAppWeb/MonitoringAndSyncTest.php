<?php

use App\Enums\WhatsAppWebState;
use App\Models\Connection;
use App\Models\User;
use App\Services\Operations\HealthStatus;
use App\Services\Operations\SystemHealth;
use App\Services\WhatsAppWeb\WhatsAppWebMonitor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\WhatsAppWebFixtures;

/*
| Monitoring of the WhatsApp Web (QR) modality in Sistema and the scheduled
| reconciliation with the Node service (down, restarted, session lost,
| orphan sessions). The service is a double.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    WhatsAppWebFixtures::configure();
    $this->service = WhatsAppWebFixtures::SERVICE;
    $this->connection = Connection::factory()->whatsAppWeb()->create(['name' => 'Cobranza QR', 'last_inbound_at' => now()->subMinutes(3)]);
    $this->status = fn (array $sessions) => Http::response([
        'service' => 'chatmanager-whatsapp-web', 'version' => '1.0.0', 'library' => 'baileys 7.0.0', 'node' => 'v22.11.0', 'uptime_seconds' => 4321, 'sessions' => $sessions,
    ]);
});

test('the system page shows the service and the real state of each session, apart from Cloud API', function () {
    Connection::factory()->verified()->create(['name' => 'Oficial']);
    Http::fake([
        $this->service.'/health' => Http::response(['status' => 'ok']),
        $this->service.'/v1/status' => ($this->status)([WhatsAppWebFixtures::session($this->connection, 'connected', ['phone' => '5215512345678', 'reconnects' => 1, 'connected_since' => now()->subHour()->getTimestamp()])]),
    ]);

    $this->actingAs(User::factory()->superAdministrator()->create())
        ->get(route('admin.system.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('whatsappWeb.enabled', true)
            ->where('whatsappWeb.service.available', true)
            ->where('whatsappWeb.service.version', '1.0.0')
            ->where('whatsappWeb.service.library', 'baileys 7.0.0')
            ->where('whatsappWeb.service.uptime_seconds', 4321)
            ->has('whatsappWeb.sessions', 1)
            ->where('whatsappWeb.sessions.0.connection_id', $this->connection->id)
            ->where('whatsappWeb.sessions.0.name', 'Cobranza QR')
            ->where('whatsappWeb.sessions.0.state', 'connected')
            ->where('whatsappWeb.sessions.0.can_send', true)
            ->where('whatsappWeb.sessions.0.phone', '+52 55 1234 5678')
            ->where('whatsappWeb.sessions.0.reconnects', 1)
            ->where('whatsappWeb.sessions.0.last_error', null)
            ->has('whatsappWeb.sessions.0.last_inbound_at')
            ->where('checks', fn ($checks) => collect($checks)->firstWhere('key', 'whatsapp_web')['status'] === 'ok'));
});

test('a running service never makes a session green by itself', function () {
    $this->connection->forceFill(['web_session_state' => WhatsAppWebState::LoggedOut, 'web_session_linked_at' => null, 'web_session_reason' => 'logged_out_from_phone', 'web_session_error' => 'La sesión se cerró desde el teléfono.'])->save();
    Http::fake([
        $this->service.'/health' => Http::response(['status' => 'ok']),
        $this->service.'/v1/status' => ($this->status)([]),
    ]);

    $overview = app(WhatsAppWebMonitor::class)->overview();
    $check = app(WhatsAppWebMonitor::class)->healthCheck();

    expect($overview['service']['available'])->toBeTrue()
        ->and($overview['sessions'][0]['state'])->toBe('logged_out')
        ->and($overview['sessions'][0]['can_send'])->toBeFalse()
        ->and($overview['sessions'][0]['last_error'])->toContain('desde el teléfono')
        ->and($check?->status)->toBe(HealthStatus::Warning)
        ->and($check?->summary)->toContain('Cobranza QR');
});

test('a service that does not answer is a warning and no session can send', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));

    $overview = app(WhatsAppWebMonitor::class)->overview();
    $check = app(WhatsAppWebMonitor::class)->healthCheck();

    expect($overview['service']['available'])->toBeFalse()
        ->and($overview['sessions'][0]['can_send'])->toBeFalse()
        ->and($check?->status)->toBe(HealthStatus::Warning)
        ->and($check?->summary)->toContain('no responde')
        // WhatsApp Web being down never fails the readiness of the platform.
        ->and(collect(app(SystemHealth::class)->checks())->firstWhere('key', 'whatsapp_web')->status)->toBe(HealthStatus::Warning);
});

test('installations that do not use the modality see no new health check', function () {
    config(['whatsapp_web.enabled' => false]);
    $this->connection->forceFill(['deleted_at' => now(), 'is_active' => false])->save();
    Http::fake();

    expect(app(WhatsAppWebMonitor::class)->healthCheck())->toBeNull()
        ->and(collect(app(SystemHealth::class)->checks())->firstWhere('key', 'whatsapp_web'))->toBeNull();

    Http::assertNothingSent();
});

test('while the service is down the synchronization marks linked sessions with an error instead of leaving them connected', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));
    $stopped = Connection::factory()->whatsAppWeb()->create(['web_session_state' => WhatsAppWebState::Disconnected]);

    $this->artisan('whatsapp-web:sync')->assertSuccessful();

    $connection = $this->connection->refresh();

    expect($connection->webSessionState())->toBe(WhatsAppWebState::Error)
        ->and($connection->web_session_error)->toContain('no responde')
        ->and($connection->sendingBlockReason())->not->toBeNull()
        // The phone stays linked: it comes back when the service does.
        ->and($connection->isConfigured())->toBeTrue()
        // A session stopped on purpose is left as it was.
        ->and($stopped->refresh()->webSessionState())->toBe(WhatsAppWebState::Disconnected);
});

test('after a restart of the service the synchronization restores the real state and removes orphan sessions', function () {
    $this->connection->forceFill(['web_session_state' => WhatsAppWebState::Error, 'web_session_error_code' => 'service_unreachable', 'web_session_error' => 'El servicio de WhatsApp Web no responde en este servidor.'])->save();
    $lost = Connection::factory()->whatsAppWeb()->create(['name' => 'Sesión perdida']);
    $deleted = Connection::factory()->whatsAppWeb()->create(['deleted_at' => now(), 'is_active' => false]);

    Http::fake([
        $this->service.'/health' => Http::response(['status' => 'ok']),
        $this->service.'/v1/sessions/'.$this->connection->id => Http::response(WhatsAppWebFixtures::session($this->connection, 'connected', ['phone' => '5215512345678', 'connected_since' => now()->getTimestamp()])),
        // The service no longer has the credentials of this one.
        $this->service.'/v1/sessions/'.$lost->id => Http::response(['error' => 'unknown_session'], 404),
        $this->service.'/v1/sessions/'.$deleted->id => Http::response(['deleted' => true]),
        $this->service.'/v1/sessions/777' => Http::response(['deleted' => true]),
        $this->service.'/v1/status' => ($this->status)([
            WhatsAppWebFixtures::session($this->connection, 'connected'),
            WhatsAppWebFixtures::session($deleted, 'connected'),
            WhatsAppWebFixtures::session(777, 'disconnected'),
        ]),
    ]);

    $this->artisan('whatsapp-web:sync')->assertSuccessful();

    expect($this->connection->refresh()->webSessionState())->toBe(WhatsAppWebState::Connected)
        ->and($this->connection->web_session_error)->toBeNull()
        ->and($lost->refresh()->webSessionState())->toBe(WhatsAppWebState::LoggedOut)
        ->and($lost->isConfigured())->toBeFalse()
        ->and($lost->web_session_error)->toContain('vincularlo de nuevo');

    // Sessions of connections ChatManager no longer has are removed from the service; the others are never touched.
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === $this->service.'/v1/sessions/'.$deleted->id);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === $this->service.'/v1/sessions/777');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === $this->service.'/v1/sessions/'.$this->connection->id);
    // It never links, restores or sends by itself.
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

test('without the shared secret the synchronization does nothing', function () {
    config(['whatsapp_web.secret' => '']);
    Http::fake();

    $this->artisan('whatsapp-web:sync')->assertSuccessful();

    Http::assertNothingSent();
    expect($this->connection->refresh()->webSessionState())->toBe(WhatsAppWebState::Connected);
});
