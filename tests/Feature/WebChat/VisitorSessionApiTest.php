<?php

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\User;
use App\Models\WebChatSession;
use App\Services\WebChat\FrameTicket;
use Tests\Support\WebChatFixtures;
use Tests\Support\WebChatVisitor;

/*
| Visitor sessions of the web chat widget (/api/v1/widget): a session only
| starts from an authorized frame load (ticket) on an active connection,
| its token is shown once and kept as a hash, and expired, revoked or
| foreign credentials are a uniform 401.
*/

beforeEach(function () {
    $this->connection = WebChatFixtures::connection();
    $this->widget = $this->connection->webChatWidget;
});

test('a valid ticket starts a session whose token is only returned once', function () {
    $response = WebChatVisitor::start($this, $this->connection, [
        'profile' => ['name' => 'Ana', 'email' => 'ana@tienda.test'],
        'landing_url' => 'https://tienda.test/zapatos?utm_source=x',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.identified', false)
        ->assertJsonPath('data.profile.name', 'Ana')
        ->assertJsonPath('data.conversation', ['status' => 'none', 'agent_name' => null])
        ->assertJsonPath('data.availability.online', true)
        ->assertJsonPath('data.realtime.enabled', false)
        ->assertJsonPath('data.poll', ['visible_seconds' => 4, 'hidden_seconds' => 20])
        ->assertHeader('Cache-Control', 'no-store, private');

    $token = $response->json('token');
    $session = WebChatSession::query()->sole();

    expect($token)->toStartWith('wvs_'.$session->public_id.'_')
        ->and($response->json('data.id'))->toBe($session->public_id)
        ->and($session->token_hash)->toBe(hash('sha256', substr($token, -43)))
        ->and($session->origin)->toBe('https://tienda.test')
        ->and($session->ip_hash)->toBe(WebChatSession::hashIp('127.0.0.1'))
        ->and($session->visitor_id)->toStartWith('wv:')
        ->and($session->contact_id)->toBeNull()
        ->and(json_encode($session->getAttributes()))->not->toContain(substr($token, -43))
        ->and(Contact::query()->count())->toBe(0)
        ->and(Conversation::query()->count())->toBe(0);

    $this->withToken($token)->getJson(route('api.v1.widget.session.show'))
        ->assertOk()
        ->assertJsonPath('data.id', $session->public_id)
        ->assertJsonMissingPath('token');
});

test('a forged, expired or foreign ticket is refused with 403', function (Closure $ticket) {
    $this->postJson(route('api.v1.widget.sessions.store', $this->widget->public_key), ['ticket' => $ticket($this)])
        ->assertForbidden()
        ->assertJson(['code' => 'forbidden']);

    expect(WebChatSession::query()->count())->toBe(0);
})->with([
    'garbage' => [fn () => 'not-a-ticket'],
    'altered origin' => [fn ($test) => preg_replace('/\.[^.]+\./', '.'.rtrim(strtr(base64_encode('https://atacante.test'), '+/', '-_'), '=').'.', WebChatVisitor::ticket($test->widget))],
    'expired' => [function ($test) {
        $ticket = WebChatVisitor::ticket($test->widget);
        $test->travel(11)->minutes();

        return $ticket;
    }],
    'another widget' => [fn () => WebChatVisitor::ticket(WebChatFixtures::connection()->webChatWidget)],
    'origin no longer allowed' => [function ($test) {
        $ticket = WebChatVisitor::ticket($test->widget);
        $test->widget->forceFill(['allowed_origins' => ['https://otra.test']])->save();

        return $ticket;
    }],
]);

test('the frame ticket only binds an allowed origin', function () {
    $tickets = app(FrameTicket::class);

    expect($tickets->verify($this->widget, $tickets->issue($this->widget, 'HTTPS://Tienda.test:443')))->toBe('https://tienda.test')
        ->and($tickets->verify($this->widget, $tickets->issue($this->widget, 'https://atacante.test')))->toBeNull();
});

test('an unknown key or a deleted connection is a 404', function (Closure $key) {
    $this->postJson(route('api.v1.widget.sessions.store', $key($this)), ['ticket' => WebChatVisitor::ticket($this->widget)])
        ->assertNotFound()
        ->assertExactJson(['message' => 'No encontrado.', 'code' => 'not_found']);
})->with([
    'unknown key' => [fn () => str_repeat('a', 48)],
    'deleted connection' => [function ($test) {
        $test->connection->forceFill(['deleted_at' => now()])->save();

        return $test->widget->public_key;
    }],
]);

test('an inactive connection answers widget_unavailable', function () {
    $this->connection->forceFill(['is_active' => false])->save();

    WebChatVisitor::start($this, $this->connection)
        ->assertStatus(409)
        ->assertJson(['code' => 'widget_unavailable']);
});

test('the visitor can close the session and its token stops working', function () {
    $token = WebChatVisitor::token($this, $this->connection);

    $this->withToken($token)->deleteJson(route('api.v1.widget.session.destroy'))->assertNoContent();

    expect(WebChatSession::query()->sole())->revoke_reason->toBe('visitor_closed');
    $this->withToken($token)->getJson(route('api.v1.widget.session.show'))->assertUnauthorized();
});

test('an expired, revoked, malformed or foreign credential is the same 401', function (Closure $token) {
    $this->withToken($token($this))->getJson(route('api.v1.widget.session.show'))
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer')
        ->assertExactJson(['message' => 'No autenticado.', 'code' => 'unauthenticated']);
})->with([
    'expired' => [fn ($test) => WebChatFixtures::session($test->connection, ['expires_at' => now()->subMinute()])['token']],
    'revoked' => [fn ($test) => WebChatFixtures::session($test->connection, ['revoked_at' => now(), 'revoke_reason' => 'key_rotated'])['token']],
    'altered secret' => [fn ($test) => substr(WebChatFixtures::session($test->connection)['token'], 0, -2).'zz'],
    'integration token' => [fn () => WebChatFixtures::apiToken()['plain']],
    'deleted connection' => [function ($test) {
        $token = WebChatFixtures::session($test->connection)['token'];
        $test->connection->forceFill(['deleted_at' => now()])->save();

        return $token;
    }],
]);

test('a request without bearer token never falls back to the web session', function () {
    $this->actingAs(User::factory()->administrator()->create())
        ->getJson(route('api.v1.widget.session.show'))
        ->assertUnauthorized();
});

test('rotating the public key revokes the sessions already open', function () {
    $token = WebChatVisitor::token($this, $this->connection);

    $this->widget->rotatePublicKey();

    $this->withToken($token)->getJson(route('api.v1.widget.session.show'))->assertUnauthorized();
    expect(WebChatSession::query()->sole()->revoke_reason)->toBe('key_rotated');
});

test('a ticket issued before rotating the public key no longer starts a session', function () {
    $ticket = WebChatVisitor::ticket($this->widget);

    $this->widget->rotatePublicKey();

    $this->postJson(route('api.v1.widget.sessions.store', $this->widget->public_key), ['ticket' => $ticket])
        ->assertForbidden();
    expect(WebChatSession::query()->count())->toBe(0);
});

test('removing a site from the allowed origins ends the sessions opened from it', function () {
    $token = WebChatVisitor::token($this, $this->connection);

    $this->widget->forceFill(['allowed_origins' => ['https://tienda.test', 'https://otro.test']])->save();
    WebChatVisitor::send($this, $token)->assertCreated();

    $this->widget->forceFill(['allowed_origins' => ['https://otro.test']])->save();

    WebChatVisitor::send($this, $token)->assertUnauthorized();
    $this->withToken($token)->getJson(route('api.v1.widget.session.show'))->assertUnauthorized();
});

test('activity slides the expiry, never beyond the absolute cap', function () {
    $this->widget->forceFill(['session_idle_days' => 30])->save();
    ['session' => $session, 'token' => $token] = WebChatFixtures::session($this->connection, ['last_seen_at' => now()->subHour()]);
    $session->forceFill(['created_at' => now()->subDays(80)])->save();

    $this->withToken($token)->getJson(route('api.v1.widget.session.show'))->assertOk();

    expect($session->refresh()->expires_at->toDateString())->toBe(now()->addDays(10)->toDateString())
        ->and($session->last_seen_at->diffInSeconds(now()))->toBeLessThan(5);

    $this->travel(11)->days();
    $this->withToken($token)->getJson(route('api.v1.widget.session.show'))->assertUnauthorized();
});

test('a new session never outlives the absolute cap', function () {
    config(['webchat.session.absolute_days' => 7]);

    WebChatVisitor::start($this, $this->connection)->assertCreated();

    expect(WebChatSession::query()->sole()->expires_at->toDateString())->toBe(now()->addDays(7)->toDateString());
});

test('the prune command deletes only sessions expired or revoked long ago', function () {
    ['session' => $active] = WebChatFixtures::session($this->connection);
    ['session' => $recent] = WebChatFixtures::session($this->connection, ['expires_at' => now()->subDays(2)]);
    WebChatFixtures::session($this->connection, ['expires_at' => now()->subDays(31)]);
    WebChatFixtures::session($this->connection, ['revoked_at' => now()->subDays(40), 'revoke_reason' => 'visitor_closed']);

    $this->artisan('webchat:prune-sessions')->assertSuccessful();

    expect(WebChatSession::query()->pluck('id')->sort()->values()->all())->toBe([$active->id, $recent->id]);
});
