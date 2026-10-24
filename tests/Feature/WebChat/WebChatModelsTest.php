<?php

use App\Models\WebChatSession;
use App\Models\WebChatWidget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\WebChatFixtures;

/*
| Web chat widget and visitor sessions: bearer tokens kept as hashes, exact
| origin matching, key rotation that revokes every session, and a sliding
| expiry capped by an absolute lifetime.
*/

test('a visitor token is wvs_{public_id}_{secret} and only its hash is stored', function () {
    ['session' => $session, 'token' => $token] = WebChatFixtures::session(WebChatFixtures::connection());

    expect($token)->toMatch('/^wvs_[0-9a-z]{26}_[A-Za-z0-9]{43}$/')
        ->and($token)->toStartWith('wvs_'.$session->public_id.'_')
        ->and($session->refresh()->token_hash)->toBe(hash('sha256', substr($token, -43)))
        ->and($session->toArray())->not->toHaveKey('token_hash')
        ->and(WebChatSession::findByToken($token)?->is($session))->toBeTrue()
        ->and($session->channelName())->toBe('webchat.sessions.'.$session->public_id);
});

test('a malformed, foreign or altered visitor token is not found', function (Closure $mutate) {
    ['token' => $token] = WebChatFixtures::session(WebChatFixtures::connection());

    expect(WebChatSession::findByToken($mutate($token)))->toBeNull();
})->with([
    'altered secret' => [fn (string $token): string => substr($token, 0, -1).(str_ends_with($token, 'a') ? 'b' : 'a')],
    'integration token prefix' => [fn (string $token): string => 'cm_live_'.substr($token, 4)],
    'unknown public id' => [fn (string $token): string => 'wvs_'.strtolower((string) Str::ulid()).substr($token, 30)],
    'uppercase public id' => [fn (string $token): string => 'wvs_'.strtoupper(substr($token, 4, 26)).substr($token, 30)],
    'empty' => [fn (): string => ''],
]);

test('only an unexpired, unrevoked session is usable', function (Closure $session, bool $usable) {
    expect($session()->isUsable())->toBe($usable);
})->with([
    'active' => [fn () => WebChatSession::factory()->create(), true],
    'expired' => [fn () => WebChatSession::factory()->expired()->create(), false],
    'revoked' => [fn () => WebChatSession::factory()->revoked()->create(), false],
]);

test('origins are normalized before an exact comparison', function (string $origin, bool $allowed) {
    $widget = WebChatWidget::factory()->create(['allowed_origins' => ['https://tienda.test', 'http://localhost:5173']]);

    expect($widget->allowsOrigin($origin))->toBe($allowed);
})->with([
    'same origin' => ['https://tienda.test', true],
    'uppercase' => ['HTTPS://Tienda.TEST', true],
    'default port' => ['https://tienda.test:443', true],
    'trailing slash' => ['https://tienda.test/', true],
    'explicit port' => ['http://localhost:5173', true],
    'other port' => ['http://localhost:5174', false],
    'other scheme' => ['http://tienda.test', false],
    'subdomain' => ['https://www.tienda.test', false],
    'suffix attack' => ['https://tienda.test.atacante.test', false],
    'with a path' => ['https://tienda.test/pagina', false],
    'with credentials' => ['https://usuario@tienda.test', false],
    'not a url' => ['tienda.test', false],
    'wildcard' => ['https://*.tienda.test', false],
]);

test('rotating the public key revokes every session of the connection', function () {
    $connection = WebChatFixtures::connection();
    $widget = $connection->webChatWidget;
    $oldKey = $widget->public_key;
    ['session' => $active] = WebChatFixtures::session($connection);
    $alreadyRevoked = WebChatSession::factory()->revoked('visitor_closed')->create(['connection_id' => $connection->id]);
    ['session' => $otherConnection] = WebChatFixtures::session(WebChatFixtures::connection());

    $widget->rotatePublicKey();

    expect($widget->refresh()->public_key)->not->toBe($oldKey)->toHaveLength(48)
        ->and($widget->key_rotated_at)->not->toBeNull()
        ->and($active->refresh())->revoke_reason->toBe('key_rotated')->isUsable()->toBeFalse()
        ->and($alreadyRevoked->refresh()->revoke_reason)->toBe('visitor_closed')
        ->and($otherConnection->refresh()->isUsable())->toBeTrue();
});

test('the identity secret is encrypted, hidden and returned only when rotated', function () {
    $widget = WebChatWidget::factory()->create();
    $initial = $widget->identity_secret;

    $secret = $widget->rotateIdentitySecret();
    $stored = DB::table('web_chat_widgets')->where('id', $widget->id)->value('identity_secret');

    expect($secret)->toHaveLength(64)->not->toBe($initial)
        ->and($widget->refresh()->identity_secret)->toBe($secret)
        ->and($widget->identity_secret_rotated_at)->not->toBeNull()
        ->and($stored)->not->toContain($secret)
        ->and($widget->toArray())->not->toHaveKey('identity_secret');
});

test('the expiry slides with activity but never beyond the absolute lifetime', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC'));
    $connection = WebChatFixtures::connection(['session_idle_days' => 30]);
    ['session' => $session] = WebChatFixtures::session($connection);

    $this->travel(10)->days();
    $session->slideExpiry();
    expect($session->refresh()->expires_at->toDateTimeString())->toBe('2026-11-10 12:00:00')
        ->and($session->last_seen_at->toDateTimeString())->toBe('2026-10-11 12:00:00');

    $this->travel(70)->days();
    $session->slideExpiry();
    expect($session->refresh()->expires_at->toDateTimeString())->toBe('2026-12-30 12:00:00');
});

test('IP addresses are only kept as a keyed hash', function () {
    expect(WebChatSession::hashIp('203.0.113.7'))->toBe(hash_hmac('sha256', '203.0.113.7', (string) config('app.key')))
        ->not->toBe(hash('sha256', '203.0.113.7'))
        ->and(WebChatSession::hashIp(null))->toBeNull();
});
