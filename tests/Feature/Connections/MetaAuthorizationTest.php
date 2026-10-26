<?php

use App\Enums\AuditAction;
use App\Enums\ConnectionChannel;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Messenger and Instagram connections authorized with Meta (OAuth). Every
| call to Meta is faked: nothing leaves the test.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'services.meta.app_id' => '1234567890',
        'services.meta.app_secret' => 'facebook-app-secret-value',
        'services.meta.instagram_app_id' => '9876543210',
        'services.meta.instagram_app_secret' => 'instagram-app-secret-value',
        'services.meta.webhook_verify_token' => 'verify-token-1234567890',
        'services.meta.login_config_id' => null,
        'services.meta.graph_version' => 'v26.0',
    ]);
    $this->admin = User::factory()->administrator()->create();
});

/**
 * Start the flow and return the state Meta would send back.
 */
function startMetaAuthorization(mixed $test, string $channel): string
{
    $response = $test->actingAs($test->admin)->post(route('connections.meta.authorize', $channel));
    $response->assertRedirect();
    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

    return (string) $query['state'];
}

function fakeFacebookPages(bool $refuseLongLivedExchange = false): void
{
    Http::fake([
        'graph.facebook.com/v26.0/oauth/access_token*' => $refuseLongLivedExchange
            ? Http::sequence()
                ->push(['access_token' => 'business-token', 'token_type' => 'bearer'])
                ->push(['error' => ['code' => 100, 'message' => 'Unsupported grant type']], 400)
            : Http::sequence()
                ->push(['access_token' => 'short-user-token', 'token_type' => 'bearer'])
                ->push(['access_token' => 'long-user-token', 'token_type' => 'bearer', 'expires_in' => 5183944]),
        'graph.facebook.com/v26.0/me/permissions*' => Http::response(['data' => [
            ['permission' => 'pages_messaging', 'status' => 'granted'],
            ['permission' => 'pages_show_list', 'status' => 'granted'],
            ['permission' => 'pages_read_engagement', 'status' => 'declined'],
        ]]),
        'graph.facebook.com/v26.0/me/accounts*' => Http::response(['data' => [
            ['id' => '1122334455', 'name' => 'Tienda Centro', 'access_token' => 'PAGE-TOKEN-1', 'tasks' => ['MESSAGING', 'MODERATE'], 'picture' => ['data' => ['url' => 'https://scontent.xx.fbcdn.net/p.jpg']]],
            ['id' => '5566778899', 'name' => 'Tienda Norte', 'access_token' => 'PAGE-TOKEN-2', 'tasks' => ['ANALYZE']],
        ]]),
        'graph.facebook.com/v26.0/me?*' => Http::response(['id' => '7001']),
        'graph.facebook.com/v26.0/1122334455/subscribed_apps*' => function (Request $request) {
            return $request->method() === 'POST'
                ? Http::response(['success' => true])
                : Http::response(['data' => [['id' => '1234567890', 'subscribed_fields' => ['messages', 'message_deliveries', 'message_reads', 'message_echoes', 'messaging_postbacks']]]]);
        },
    ]);
}

test('starting the Messenger authorization redirects to Facebook Login with a single-use state', function () {
    $response = $this->actingAs($this->admin)->post(route('connections.meta.authorize', 'messenger'));

    $location = (string) $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://www.facebook.com/v26.0/dialog/oauth?')
        ->and($query)->toMatchArray([
            'client_id' => '1234567890',
            'redirect_uri' => route('connections.meta.callback', 'messenger'),
            'response_type' => 'code',
            'scope' => 'pages_show_list,pages_messaging,pages_manage_metadata,pages_read_engagement',
        ])
        ->and($query['state'])->toHaveLength(40)
        ->and(session('meta_oauth.state.hash'))->toBe(hash('sha256', $query['state']));
    Http::assertNothingSent();
});

test('Facebook Login for Business uses the configuration id instead of scopes', function () {
    config(['services.meta.login_config_id' => '445566']);

    $location = (string) $this->actingAs($this->admin)->post(route('connections.meta.authorize', 'messenger'))->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($query)->toHaveKey('config_id', '445566')->not->toHaveKey('scope');
});

test('Instagram uses Instagram Login with its own app id and the messaging scopes', function () {
    $location = (string) $this->actingAs($this->admin)->post(route('connections.meta.authorize', 'instagram'))->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://www.instagram.com/oauth/authorize?')
        ->and($query)->toMatchArray([
            'client_id' => '9876543210',
            'redirect_uri' => route('connections.meta.callback', 'instagram'),
            'scope' => 'instagram_business_basic,instagram_business_manage_messages',
            'response_type' => 'code',
        ]);
});

test('without the Meta app configured the channel explains what the server needs and never redirects', function () {
    config(['services.meta.app_id' => null, 'services.meta.app_secret' => null]);

    $this->actingAs($this->admin)->get(route('connections.meta.show', 'messenger'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('connections/MetaAuthorize')
            ->where('configured', false)
            ->where('missing', ['META_APP_ID', 'META_APP_SECRET']));

    $this->actingAs($this->admin)->post(route('connections.meta.authorize', 'messenger'))
        ->assertRedirect(route('connections.meta.show', 'messenger'));

    expect(session('meta_oauth.state'))->toBeNull();
});

test('the callback rejects a missing, mismatched, expired or reused state without calling Meta', function () {
    Http::fake();

    // Missing.
    $this->actingAs($this->admin)->get(route('connections.meta.callback', ['channel' => 'messenger', 'code' => 'abc']))
        ->assertRedirect(route('connections.meta.show', 'messenger'));

    // Mismatched (and the stored one is consumed by the attempt).
    $state = startMetaAuthorization($this, 'messenger');
    $this->get(route('connections.meta.callback', ['channel' => 'messenger', 'code' => 'abc', 'state' => ($state[0] === 'x' ? 'y' : 'x').substr($state, 1)]));
    $this->get(route('connections.meta.callback', ['channel' => 'messenger', 'code' => 'abc', 'state' => $state]))
        ->assertRedirect(route('connections.meta.show', 'messenger'));

    // Issued for another channel.
    $state = startMetaAuthorization($this, 'instagram');
    $this->get(route('connections.meta.callback', ['channel' => 'messenger', 'code' => 'abc', 'state' => $state]))
        ->assertRedirect(route('connections.meta.show', 'messenger'));

    // Expired.
    $state = startMetaAuthorization($this, 'messenger');
    $this->travel(11)->minutes();
    $this->get(route('connections.meta.callback', ['channel' => 'messenger', 'code' => 'abc', 'state' => $state]))
        ->assertRedirect(route('connections.meta.show', 'messenger'));

    Http::assertNothingSent();
    expect(Connection::query()->count())->toBe(0);
});

test('a state issued to another administrator is refused', function () {
    Http::fake();
    $state = startMetaAuthorization($this, 'messenger');
    $other = User::factory()->administrator()->create();

    $this->actingAs($other)->get(route('connections.meta.callback', ['channel' => 'messenger', 'code' => 'abc', 'state' => $state]))
        ->assertRedirect(route('connections.meta.show', 'messenger'));

    Http::assertNothingSent();
});

test('a refused authorization creates nothing and says so', function () {
    Http::fake();
    $state = startMetaAuthorization($this, 'messenger');

    $this->get(route('connections.meta.callback', ['channel' => 'messenger', 'state' => $state, 'error' => 'access_denied', 'error_reason' => 'user_denied']))
        ->assertRedirect(route('connections.meta.show', 'messenger'))
        ->assertInertiaFlash('toast.type', 'warning');

    Http::assertNothingSent();
    expect(Connection::query()->count())->toBe(0);
});

test('the code is exchanged on the server for a long-lived token and the pages are offered without their tokens', function () {
    fakeFacebookPages();
    $state = startMetaAuthorization($this, 'messenger');

    $this->get(route('connections.meta.callback', ['channel' => 'messenger', 'state' => $state, 'code' => 'the-code']))
        ->assertRedirect(route('connections.meta.accounts', 'messenger'));

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'oauth/access_token')
        && ($request->data()['code'] ?? null) === 'the-code' && ($request->data()['client_secret'] ?? null) === 'facebook-app-secret-value'
        && ($request->data()['redirect_uri'] ?? null) === route('connections.meta.callback', 'messenger'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'oauth/access_token') && ($request->data()['grant_type'] ?? null) === 'fb_exchange_token' && ($request->data()['fb_exchange_token'] ?? null) === 'short-user-token');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'me/accounts')
        && $request->hasHeader('Authorization', 'Bearer long-user-token')
        && ($request->data()['appsecret_proof'] ?? null) === hash_hmac('sha256', 'long-user-token', 'facebook-app-secret-value'));

    $this->get(route('connections.meta.accounts', 'messenger'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('connections/MetaAccounts')
            ->has('accounts', 2)
            ->where('accounts.0.id', '1122334455')
            ->where('accounts.0.name', 'Tienda Centro')
            ->where('accounts.0.can_message', true)
            ->where('accounts.1.can_message', false)
            ->missing('accounts.0.access_token'))
        ->assertDontSee('PAGE-TOKEN-1');
});

test('choosing a page creates an inactive connection with the encrypted page token, subscribes it and audits the authorization', function () {
    fakeFacebookPages();
    $state = startMetaAuthorization($this, 'messenger');
    $this->get(route('connections.meta.callback', ['channel' => 'messenger', 'state' => $state, 'code' => 'the-code']));

    $this->post(route('connections.meta.connect', 'messenger'), ['account_id' => '1122334455'])
        ->assertRedirect();

    $connection = Connection::query()->sole();
    expect($connection)
        ->channel->toBe(ConnectionChannel::Messenger)
        ->external_account_id->toBe('1122334455')
        ->external_account_name->toBe('Tienda Centro')
        ->access_token->toBe('PAGE-TOKEN-1')
        ->granted_scopes->toBe(['pages_messaging', 'pages_show_list'])
        ->token_expires_at->toBeNull()
        ->authorized_by_user_id->toBe($this->admin->id)
        ->is_active->toBeFalse()
        ->app_subscribed->toBeTrue()
        ->and(DB::table('connections')->value('access_token'))->not->toContain('PAGE-TOKEN-1');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '1122334455/subscribed_apps')
        && str_contains($request->url(), 'appsecret_proof='.hash_hmac('sha256', 'PAGE-TOKEN-1', 'facebook-app-secret-value'))
        && ($request->data()['subscribed_fields'] ?? null) === 'messages,message_deliveries,message_reads,message_echoes,messaging_postbacks,messaging_policy_enforcement');

    $audit = AuditLog::query()->where('action', AuditAction::ConnectionMetaAuthorized)->sole();
    expect($audit->properties)->toMatchArray(['channel' => 'messenger', 'external_account_id' => '1122334455', 'subscribed' => true, 'reauthorized' => false])
        ->and(json_encode($audit->properties))->not->toContain('PAGE-TOKEN');

    // The pending selection is single-use as well.
    $this->post(route('connections.meta.connect', 'messenger'), ['account_id' => '1122334455'])
        ->assertRedirect(route('connections.meta.show', 'messenger'));
    expect(Connection::query()->count())->toBe(1);
});

test('a tampered account choice creates nothing, subscribes nothing and audits nothing', function (string $channel, string $accountId) {
    fakeFacebookPages();
    $state = startMetaAuthorization($this, 'messenger');
    $this->get(route('connections.meta.callback', ['channel' => 'messenger', 'state' => $state, 'code' => 'the-code']));

    $this->post(route('connections.meta.connect', $channel), ['account_id' => $accountId])->assertRedirect();

    expect(Connection::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditAction::ConnectionMetaAuthorized)->count())->toBe(0);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), 'subscribed_apps'));
})->with([
    'an account Meta never offered' => ['messenger', '999'],
    'a page without the messaging task' => ['messenger', '5566778899'],
    'a page id posted to the other channel' => ['instagram', '1122334455'],
]);

test('with Login for Business a refused long-lived exchange keeps the code token', function () {
    config(['services.meta.login_config_id' => '445566']);
    fakeFacebookPages(refuseLongLivedExchange: true);
    $state = startMetaAuthorization($this, 'messenger');

    $this->get(route('connections.meta.callback', ['channel' => 'messenger', 'state' => $state, 'code' => 'the-code']))
        ->assertRedirect(route('connections.meta.accounts', 'messenger'));

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'me/accounts') && $request->hasHeader('Authorization', 'Bearer business-token'));
});

test('authorizing the same page again refreshes its token on the existing connection', function () {
    $existing = Connection::factory()->messenger()->create(['name' => 'Página original']);
    $existing->forceFill(['external_account_id' => '1122334455', 'access_token' => 'OLD-TOKEN'])->save();
    fakeFacebookPages();
    $state = startMetaAuthorization($this, 'messenger');
    $this->get(route('connections.meta.callback', ['channel' => 'messenger', 'state' => $state, 'code' => 'the-code']));

    $this->post(route('connections.meta.connect', 'messenger'), ['account_id' => '1122334455'])
        ->assertRedirect(route('connections.show', $existing));

    expect(Connection::query()->count())->toBe(1)
        ->and($existing->refresh())->access_token->toBe('PAGE-TOKEN-1')->name->toBe('Página original');
});

test('an Instagram account is connected with a 60-day token through Instagram Login', function () {
    Http::fake([
        'api.instagram.com/oauth/access_token' => Http::response(['data' => [['access_token' => 'IG-SHORT', 'user_id' => '9001', 'permissions' => 'instagram_business_basic,instagram_business_manage_messages']]]),
        'graph.instagram.com/access_token*' => Http::response(['access_token' => 'IG-LONG', 'token_type' => 'bearer', 'expires_in' => 5184000]),
        'graph.instagram.com/v26.0/me?*' => Http::response(['user_id' => '17841400000000001', 'username' => 'tienda.mx', 'name' => 'Tienda MX', 'profile_picture_url' => 'https://scontent.cdninstagram.com/a.jpg']),
        'graph.instagram.com/v26.0/me/subscribed_apps*' => fn (Request $request) => $request->method() === 'POST'
            ? Http::response(['success' => true])
            : Http::response(['data' => [['subscribed_fields' => ['messages', 'messaging_seen', 'messaging_postbacks', 'message_reactions']]]]),
    ]);
    $state = startMetaAuthorization($this, 'instagram');

    $this->get(route('connections.meta.callback', ['channel' => 'instagram', 'state' => $state, 'code' => 'ig-code']))
        ->assertRedirect(route('connections.meta.accounts', 'instagram'));
    $this->post(route('connections.meta.connect', 'instagram'), ['account_id' => '17841400000000001'])->assertRedirect();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.instagram.com/oauth/access_token'
        && ($request->data()['client_id'] ?? null) === '9876543210' && ($request->data()['client_secret'] ?? null) === 'instagram-app-secret-value' && ($request->data()['grant_type'] ?? null) === 'authorization_code');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'graph.instagram.com/v26.0/me/subscribed_apps')
        && str_contains($request->url(), 'appsecret_proof='.hash_hmac('sha256', 'IG-LONG', 'instagram-app-secret-value')));

    expect(Connection::query()->sole())
        ->channel->toBe(ConnectionChannel::Instagram)
        ->access_token->toBe('IG-LONG')
        ->external_account_name->toBe('Tienda MX')
        ->token_expires_at->toBeInstanceOf(CarbonInterface::class)
        ->app_subscribed->toBeTrue();
});

test('Meta authorization requires connections.manage with the organization-wide scope', function (Closure $makeUser) {
    Http::fake();
    $user = $makeUser();
    $connection = Connection::factory()->messenger()->create();

    $this->actingAs($user)->get(route('connections.meta.show', 'messenger'))->assertForbidden();
    $this->actingAs($user)->post(route('connections.meta.authorize', 'messenger'))->assertForbidden();
    $this->actingAs($user)->get(route('connections.meta.callback', ['channel' => 'messenger', 'code' => 'a', 'state' => 'b']))->assertForbidden();
    $this->actingAs($user)->get(route('connections.meta.accounts', 'messenger'))->assertForbidden();
    $this->actingAs($user)->post(route('connections.meta.connect', 'messenger'), ['account_id' => '1'])->assertForbidden();
    $this->actingAs($user)->post(route('connections.meta.disconnect', $connection))->assertForbidden();

    Http::assertNothingSent();
    expect($connection->refresh()->access_token)->not->toBeNull();
})->with([
    'agent' => [fn () => User::factory()->agent()->create()],
    'viewer only' => [fn () => User::factory()->withRole(Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewConnections])->create())->create()],
    'manage without global scope' => [fn () => User::factory()->withRole(Role::factory()->scope(DataScope::Departments)->withPermissions([Permission::ViewConnections, Permission::ManageConnections])->create())->create()],
]);

test('disconnecting cancels the subscription, forgets the token and keeps the history', function () {
    $connection = Connection::factory()->messenger()->create();
    $token = $connection->access_token;
    Http::fake(["graph.facebook.com/v26.0/{$connection->external_account_id}/subscribed_apps*" => Http::response(['success' => true])]);

    $this->actingAs($this->admin)->post(route('connections.meta.disconnect', $connection))->assertRedirect();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), 'appsecret_proof='.hash_hmac('sha256', (string) $token, 'facebook-app-secret-value')));
    expect($connection->refresh())
        ->access_token->toBeNull()
        ->granted_scopes->toBeNull()
        ->is_active->toBeFalse()
        ->app_subscribed->toBeFalse()
        ->isDeleted()->toBeFalse()
        ->external_account_id->not->toBeNull()
        ->and(AuditLog::query()->where('action', AuditAction::ConnectionMetaDisconnected)->sole()->properties)
        ->toMatchArray(['reason' => 'administrator', 'unsubscribed' => true]);
});

test('a WhatsApp connection cannot be disconnected through the Meta authorization endpoint', function () {
    $connection = Connection::factory()->create();

    $this->actingAs($this->admin)->post(route('connections.meta.disconnect', $connection))->assertNotFound();

    expect($connection->refresh()->access_token)->not->toBeNull();
});

test('Meta deauthorization callback disconnects the connections authorized by that person', function () {
    $connection = Connection::factory()->messenger()->create();
    $connection->forceFill(['provider_signals' => ['meta_authorization' => ['at' => now()->toIso8601String(), 'value' => ['user_id' => '7001']]]])->save();
    $other = Connection::factory()->messenger()->create();

    $payload = rtrim(strtr(base64_encode((string) json_encode(['algorithm' => 'HMAC-SHA256', 'issued_at' => time(), 'user_id' => '7001'])), '+/', '-_'), '=');
    $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, 'facebook-app-secret-value', true)), '+/', '-_'), '=');

    $this->post(route('meta.deauthorize'), ['signed_request' => 'AAAA.'.$payload])->assertStatus(400);
    expect($connection->refresh()->access_token)->not->toBeNull();

    $this->post(route('meta.deauthorize'), ['signed_request' => $signature.'.'.$payload])->assertOk();
    expect($connection->refresh()->access_token)->toBeNull()
        ->and($connection->credentials_error)->not->toBeNull()
        ->and($other->refresh()->access_token)->not->toBeNull();

    $deletion = $this->post(route('meta.data-deletion'), ['signed_request' => $signature.'.'.$payload])->assertOk();
    expect($deletion->json('confirmation_code'))->toBeString()
        ->and($deletion->json('url'))->toBe(route('meta.data-deletion.status', $deletion->json('confirmation_code')));
    $this->get($deletion->json('url'))->assertOk();
    Http::assertNothingSent();
});

test('a Messenger connection opened from the creation form goes to the Meta authorization instead', function () {
    $this->actingAs($this->admin)->get(route('connections.create', ['channel' => 'messenger']))
        ->assertRedirect(route('connections.meta.show', 'messenger'));
});
