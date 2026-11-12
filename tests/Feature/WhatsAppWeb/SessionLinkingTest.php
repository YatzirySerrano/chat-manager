<?php

use App\Enums\AuditAction;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Enums\WhatsAppWebState;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\WhatsAppWebFixtures;

/*
| Linking a phone by QR and operating its session from Conexiones (contract
| §8). The Node service is a double (Http::fake): nothing here proves the
| behavior of WhatsApp itself.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    WhatsAppWebFixtures::configure();
    $this->admin = User::factory()->superAdministrator()->create();
    $this->connection = Connection::factory()->whatsAppWebUnlinked()->create(['name' => 'Cobranza QR']);
    $this->service = WhatsAppWebFixtures::SERVICE;
    $this->sessionUrl = fn (string $action = '') => $this->service.'/v1/sessions/'.$this->connection->id.($action === '' ? '' : '/'.$action);
    $this->qr = fn (int $seconds = 20) => ['value' => '2@AbCdEfGhIjKlMnOpQrStUvWxYz0123456789,Zm9vYmFy,cXV4,MTIz', 'expires_at' => now()->getTimestamp() + $seconds];
});

test('linking asks the service for a QR with a signed request and returns it as an SVG', function () {
    Http::fake([
        $this->service.'/health' => Http::response(['status' => 'ok']),
        ($this->sessionUrl)('start') => Http::response(WhatsAppWebFixtures::session($this->connection, 'qr_pending', ['qr' => ($this->qr)()])),
    ]);

    $response = $this->actingAs($this->admin)
        ->postJson(route('connections.whatsapp-web.link', $this->connection))
        ->assertOk()
        ->assertJsonPath('state', 'qr_pending')
        ->assertJsonPath('state_label', 'Esperando escaneo')
        ->assertJsonPath('can_send', false)
        ->assertJsonPath('service_available', true)
        ->assertJsonPath('actions.link', false)
        ->assertJsonPath('actions.refresh', true)
        ->assertJsonPath('actions.disconnect', true)
        ->assertJsonPath('actions.reconnect', false);

    expect($response->json('qr.svg'))->toStartWith('<svg')->toContain('viewBox')
        ->and($response->json('qr.seconds_left'))->toBeGreaterThan(0)->toBeLessThanOrEqual(20);

    Http::assertSent(fn (Request $request): bool => $request->url() === ($this->sessionUrl)('start')
        && $request->method() === 'POST'
        && $request['mode'] === 'link'
        && WhatsAppWebFixtures::isSigned($request));

    $connection = $this->connection->refresh();

    expect($connection->webSessionState())->toBe(WhatsAppWebState::QrPending)
        ->and($connection->web_session_link_user_id)->toBe($this->admin->id)
        ->and($connection->isConfigured())->toBeFalse()
        ->and(AuditLog::query()->where('action', AuditAction::ConnectionWebLinkRequested)->count())->toBe(1)
        // The QR is never written to the database nor to the audit log.
        ->and(json_encode($connection->getAttributes()))->not->toContain('2@AbCd')
        ->and(json_encode(AuditLog::query()->get()->toArray()))->not->toContain('2@AbCd');
});

test('an expired QR is not shown and a new one can be requested', function () {
    Http::fake([
        $this->service.'/health' => Http::response(['status' => 'ok']),
        ($this->sessionUrl)('start') => Http::sequence()
            ->push(WhatsAppWebFixtures::session($this->connection, 'qr_pending', ['qr' => ($this->qr)(20)]))
            ->push(WhatsAppWebFixtures::session($this->connection, 'qr_pending', ['qr' => ['value' => '2@SecondCode,AAAA,BBBB,CCCC', 'expires_at' => now()->getTimestamp() + 80]])),
        // The service has no newer code yet.
        ($this->sessionUrl)() => Http::response(WhatsAppWebFixtures::session($this->connection, 'qr_pending', ['last_event_at' => now()->getTimestamp()])),
    ]);

    $this->actingAs($this->admin)->postJson(route('connections.whatsapp-web.link', $this->connection))->assertOk();

    $this->travel(30)->seconds();

    $this->actingAs($this->admin)
        ->getJson(route('connections.whatsapp-web.session', $this->connection))
        ->assertOk()
        ->assertJsonPath('state', 'qr_pending')
        ->assertJsonPath('qr', null)
        ->assertJsonPath('actions.refresh', true);

    $this->actingAs($this->admin)
        ->postJson(route('connections.whatsapp-web.refresh', $this->connection))
        ->assertOk()
        ->assertJsonPath('state', 'qr_pending')
        ->assertJsonPath('qr.seconds_left', fn (int $left): bool => $left > 0 && $left <= 50);
});

test('when the phone finishes linking the connection shows the number and is activated for the administrator who linked it', function () {
    Http::fake([
        $this->service.'/health' => Http::response(['status' => 'ok']),
        ($this->sessionUrl)('start') => Http::response(WhatsAppWebFixtures::session($this->connection, 'qr_pending', ['qr' => ($this->qr)()])),
    ]);

    $this->actingAs($this->admin)->postJson(route('connections.whatsapp-web.link', $this->connection))->assertOk();

    WhatsAppWebFixtures::event($this, $this->connection, 'session.state', WhatsAppWebFixtures::session($this->connection, 'pairing'))->assertOk();
    WhatsAppWebFixtures::event($this, $this->connection, 'session.state', WhatsAppWebFixtures::session($this->connection, 'connected', [
        'phone' => '5215587654321',
        'push_name' => 'Central de cobranza',
        'connected_since' => now()->getTimestamp(),
    ]))->assertOk();

    $connection = $this->connection->refresh();

    expect($connection->webSessionState())->toBe(WhatsAppWebState::Connected)
        ->and($connection->isConfigured())->toBeTrue()
        ->and($connection->web_session_phone)->toBe('5215587654321')
        ->and($connection->display_phone_number)->toBe('+52 55 8765 4321')
        ->and($connection->is_active)->toBeTrue()
        ->and($connection->web_session_link_user_id)->toBeNull()
        ->and($connection->sendingBlockReason())->toBeNull()
        ->and(AuditLog::query()->where('action', AuditAction::ConnectionWebLinked)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', AuditAction::ConnectionActivated)->sole()->user_id)->toBe($this->admin->id);

    $this->actingAs($this->admin)
        ->getJson(route('connections.whatsapp-web.session', $this->connection))
        ->assertOk()
        ->assertJsonPath('state', 'connected')
        ->assertJsonPath('phone', '+52 55 8765 4321')
        ->assertJsonPath('can_send', true)
        ->assertJsonPath('qr', null)
        ->assertJsonPath('actions.logout', true)
        ->assertJsonPath('actions.disconnect', true)
        ->assertJsonPath('actions.link', false);
});

test('cancelling a pending QR stops the session and leaves the connection without a linked phone', function () {
    Http::fake([
        $this->service.'/health' => Http::response(['status' => 'ok']),
        ($this->sessionUrl)('start') => Http::response(WhatsAppWebFixtures::session($this->connection, 'qr_pending', ['qr' => ($this->qr)()])),
        ($this->sessionUrl)('stop') => Http::response(WhatsAppWebFixtures::session($this->connection, 'disconnected')),
    ]);

    $this->actingAs($this->admin)->postJson(route('connections.whatsapp-web.link', $this->connection))->assertOk();

    $this->actingAs($this->admin)
        ->postJson(route('connections.whatsapp-web.disconnect', $this->connection))
        ->assertOk()
        ->assertJsonPath('state', 'unconfigured')
        ->assertJsonPath('qr', null)
        ->assertJsonPath('actions.link', true);

    expect($this->connection->refresh()->isConfigured())->toBeFalse();
});

test('disconnecting keeps the phone linked and reconnecting needs no QR', function () {
    $connection = Connection::factory()->whatsAppWeb()->create();
    $url = fn (string $action) => $this->service.'/v1/sessions/'.$connection->id.'/'.$action;
    Http::fake([
        $this->service.'/health' => Http::response(['status' => 'ok']),
        $url('stop') => Http::response(WhatsAppWebFixtures::session($connection, 'disconnected', ['phone' => '5215512345678'])),
        $url('start') => Http::response(WhatsAppWebFixtures::session($connection, 'connected', ['phone' => '5215512345678', 'connected_since' => now()->getTimestamp()])),
    ]);

    $this->actingAs($this->admin)
        ->postJson(route('connections.whatsapp-web.disconnect', $connection))
        ->assertOk()
        ->assertJsonPath('state', 'disconnected')
        ->assertJsonPath('can_send', false)
        ->assertJsonPath('actions.reconnect', true)
        ->assertJsonPath('actions.link', false);

    expect($connection->refresh()->isConfigured())->toBeTrue()
        ->and($connection->sendingBlockReason())->toContain('desconectada temporalmente');

    $this->actingAs($this->admin)
        ->postJson(route('connections.whatsapp-web.reconnect', $connection))
        ->assertOk()
        ->assertJsonPath('state', 'connected')
        ->assertJsonPath('can_send', true);

    Http::assertSent(fn (Request $request): bool => $request->url() === $url('start') && $request['mode'] === 'restore');

    expect(AuditLog::query()->whereIn('action', [AuditAction::ConnectionWebDisconnected, AuditAction::ConnectionWebReconnectRequested])->count())->toBe(2);
});

test('reconnecting a session the service no longer has asks for a new link instead of pretending', function () {
    $connection = Connection::factory()->whatsAppWeb()->create(['web_session_state' => WhatsAppWebState::Disconnected]);
    Http::fake([
        $this->service.'/health' => Http::response(['status' => 'ok']),
        $this->service.'/v1/sessions/'.$connection->id.'/start' => Http::response(['error' => 'not_linked'], 409),
    ]);

    $this->actingAs($this->admin)
        ->postJson(route('connections.whatsapp-web.reconnect', $connection))
        ->assertStatus(422)
        ->assertJsonValidationErrors('session');

    $connection->refresh();

    expect($connection->webSessionState())->toBe(WhatsAppWebState::LoggedOut)
        ->and($connection->isConfigured())->toBeFalse()
        ->and($connection->web_session_error)->toContain('vincularlo de nuevo');
});

test('closing the session of the device unlinks the phone and keeps every conversation', function () {
    $connection = Connection::factory()->whatsAppWeb()->create();
    $conversation = Conversation::factory()->create(['connection_id' => $connection->id]);
    Message::factory()->count(3)->create(['conversation_id' => $conversation->id]);
    Http::fake([
        $this->service.'/health' => Http::response(['status' => 'ok']),
        $this->service.'/v1/sessions/'.$connection->id.'/logout' => Http::response(WhatsAppWebFixtures::session($connection, 'logged_out')),
    ]);

    $this->actingAs($this->admin)
        ->postJson(route('connections.whatsapp-web.logout', $connection))
        ->assertOk()
        ->assertJsonPath('state', 'logged_out')
        ->assertJsonPath('phone', null)
        ->assertJsonPath('actions.link', true)
        ->assertJsonPath('actions.reconnect', false);

    expect($connection->refresh()->isConfigured())->toBeFalse()
        ->and($connection->isDeleted())->toBeFalse()
        ->and(Conversation::query()->where('connection_id', $connection->id)->count())->toBe(1)
        ->and(Message::query()->where('conversation_id', $conversation->id)->count())->toBe(3)
        ->and(AuditLog::query()->where('action', AuditAction::ConnectionWebLoggedOut)->count())->toBe(1);
});

test('deleting the connection removes its session from the service and keeps its history', function () {
    $connection = Connection::factory()->whatsAppWeb()->create();
    $conversation = Conversation::factory()->create(['connection_id' => $connection->id]);
    Http::fake([$this->service.'/v1/sessions/'.$connection->id => Http::response(['deleted' => true])]);

    $this->actingAs($this->admin)->delete(route('connections.destroy', $connection))->assertRedirect(route('connections.index'));

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === $this->service.'/v1/sessions/'.$connection->id
        && WhatsAppWebFixtures::isSigned($request));

    $connection->refresh();

    expect($connection->isDeleted())->toBeTrue()
        ->and($connection->webSessionState())->toBe(WhatsAppWebState::Unconfigured)
        ->and($connection->web_session_phone)->toBeNull()
        ->and(Conversation::query()->whereKey($conversation->id)->exists())->toBeTrue();
});

test('a service that does not answer is reported as unavailable and no state is invented', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to 127.0.0.1 port 3410'));

    $this->actingAs($this->admin)
        ->postJson(route('connections.whatsapp-web.link', $this->connection))
        ->assertStatus(503)
        ->assertJsonPath('link_state.service_available', false)
        ->assertJsonPath('link_state.state', 'unconfigured')
        ->assertJsonPath('link_state.can_send', false);

    expect($this->connection->refresh()->webSessionState())->toBe(WhatsAppWebState::Unconfigured);

    $linked = Connection::factory()->whatsAppWeb()->create();
    Cache::flush();

    // Even a session stored as connected cannot send while the service is down.
    $this->actingAs($this->admin)
        ->getJson(route('connections.whatsapp-web.session', $linked))
        ->assertOk()
        ->assertJsonPath('state', 'connected')
        ->assertJsonPath('service_available', false)
        ->assertJsonPath('can_send', false);
});

test('without the shared secret nothing can be linked', function () {
    config(['whatsapp_web.secret' => '']);
    Http::fake();

    $this->actingAs($this->admin)
        ->postJson(route('connections.whatsapp-web.link', $this->connection))
        ->assertStatus(503);

    Http::assertNothingSent();
});

test('only people who manage connections operate a session and the QR is never shown to anyone else', function () {
    Http::fake([
        $this->service.'/health' => Http::response(['status' => 'ok']),
        ($this->sessionUrl)('start') => Http::response(WhatsAppWebFixtures::session($this->connection, 'qr_pending', ['qr' => ($this->qr)(60)])),
        ($this->sessionUrl)() => Http::response(WhatsAppWebFixtures::session($this->connection, 'qr_pending', ['qr' => ($this->qr)(60)])),
    ]);

    $viewer = User::factory()->withRole(Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewConnections])->create())->create();
    $agent = User::factory()->agent()->create();

    foreach (['link', 'refresh', 'disconnect', 'reconnect', 'logout'] as $action) {
        $this->actingAs($viewer)->postJson(route('connections.whatsapp-web.'.$action, $this->connection))->assertForbidden();
        $this->actingAs($agent)->postJson(route('connections.whatsapp-web.'.$action, $this->connection))->assertForbidden();
    }

    $this->actingAs($agent)->getJson(route('connections.whatsapp-web.session', $this->connection))->assertForbidden();
    Http::assertNothingSent();

    $this->actingAs($this->admin)->postJson(route('connections.whatsapp-web.link', $this->connection))->assertOk()->assertJsonPath('qr.svg', fn (string $svg): bool => str_starts_with($svg, '<svg'));

    $this->actingAs($viewer)
        ->getJson(route('connections.whatsapp-web.session', $this->connection))
        ->assertOk()
        ->assertJsonPath('state', 'qr_pending')
        ->assertJsonPath('qr', null)
        ->assertJsonPath('actions', ['link' => false, 'refresh' => false, 'disconnect' => false, 'reconnect' => false, 'logout' => false]);
});

test('the session routes do not exist for a WhatsApp Cloud API connection', function () {
    Http::fake();
    $cloud = Connection::factory()->verified()->create();

    $this->actingAs($this->admin)->getJson(route('connections.whatsapp-web.session', $cloud))->assertNotFound();
    $this->actingAs($this->admin)->postJson(route('connections.whatsapp-web.link', $cloud))->assertForbidden();

    Http::assertNothingSent();
    expect($cloud->refresh()->web_session_state)->toBeNull()
        ->and($cloud->provider->value)->toBe('meta_cloud_api');
});

test('a phone cannot be linked twice nor a session of another connection be operated', function () {
    $linked = Connection::factory()->whatsAppWeb()->create();
    Http::fake([$this->service.'/health' => Http::response(['status' => 'ok'])]);

    $this->actingAs($this->admin)
        ->postJson(route('connections.whatsapp-web.link', $linked))
        ->assertStatus(422)
        ->assertJsonValidationErrors('session');

    // Nothing but the health check reached the service, and never for another connection.
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/v1/sessions/'));
    expect($this->connection->refresh()->webSessionState())->toBe(WhatsAppWebState::Unconfigured);
});
