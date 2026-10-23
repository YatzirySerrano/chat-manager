<?php

use App\Actions\Users\DeleteUser;
use App\Jobs\SendWebPush;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Notifications\AccessChangedNotification;
use App\Notifications\NotificationKind;
use App\Notifications\PruneNotifications;
use App\Notifications\Push\Vapid;
use App\Services\Operations\HealthStatus;
use App\Services\Operations\Preflight;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Web Push without payload: VAPID signature, subscriptions of the user's own
| browsers, delivery results, throttling and cleanup.
*/

const PUSH_ENDPOINT = 'https://fcm.googleapis.com/fcm/send/device-1';

/**
 * Configures a fresh VAPID key pair and returns it.
 *
 * @return array{public_key: string, private_key: string}
 */
function configureVapid(): array
{
    $keys = Vapid::generateKeys();

    config([
        'webpush.public_key' => $keys['public_key'],
        'webpush.private_key' => $keys['private_key'],
        'webpush.subject' => 'mailto:operaciones@example.com',
    ]);

    return $keys;
}

/**
 * A user with "Navegador" on for access changes and one subscribed browser.
 */
function pushRecipient(bool $browser = true): User
{
    $user = User::factory()->agent()->create();

    UserNotificationPreference::query()->create([
        'user_id' => $user->id,
        'kind' => NotificationKind::AccessChanged->value,
        'in_app' => true,
        'browser' => $browser,
        'sound' => false,
    ]);

    PushSubscription::factory()->for($user)->endpoint(PUSH_ENDPOINT)->create();

    return $user;
}

function notifyAccessChange(User $user, string $bucket = '1'): void
{
    $user->notify(new AccessChangedNotification('role_changed', $bucket));
}

test('the VAPID token is an ES256 JWT that verifies with the public key', function () {
    $keys = configureVapid();
    $this->freezeTime();

    $header = app(Vapid::class)->authorization('https://fcm.googleapis.com:443/fcm/send/abc');

    expect($header)->toMatch('/^vapid t=[\w-]+\.[\w-]+\.[\w-]+, k=[\w-]+$/');

    preg_match('/^vapid t=([\w-]+)\.([\w-]+)\.([\w-]+), k=([\w-]+)$/', $header, $parts);
    [, $encodedHeader, $encodedClaims, $encodedSignature, $publicKey] = $parts;

    $point = Vapid::base64UrlDecode($publicKey);
    $spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$point;
    $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";

    $raw = Vapid::base64UrlDecode($encodedSignature);
    $integer = function (string $bytes): string {
        $bytes = ltrim($bytes, "\x00");

        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00".$bytes;
        }

        return "\x02".chr(strlen($bytes)).$bytes;
    };
    $sequence = $integer(substr($raw, 0, 32)).$integer(substr($raw, 32));
    $der = "\x30".chr(strlen($sequence)).$sequence;

    expect($publicKey)->toBe($keys['public_key'])
        ->and(strlen($point))->toBe(65)
        ->and(strlen($raw))->toBe(64)
        ->and(openssl_verify("{$encodedHeader}.{$encodedClaims}", $der, $pem, OPENSSL_ALGO_SHA256))->toBe(1)
        ->and(json_decode(Vapid::base64UrlDecode($encodedHeader), true))->toBe(['typ' => 'JWT', 'alg' => 'ES256'])
        ->and(json_decode(Vapid::base64UrlDecode($encodedClaims), true))->toBe([
            'aud' => 'https://fcm.googleapis.com',
            'exp' => now()->addHours(12)->getTimestamp(),
            'sub' => 'mailto:operaciones@example.com',
        ]);
});

test('the VAPID audience keeps only a non-default port of the push service', function () {
    configureVapid();

    $header = app(Vapid::class)->authorization('https://push.example.com:8443/send/abc');
    preg_match('/^vapid t=[\w-]+\.([\w-]+)\./', $header, $parts);

    expect(json_decode(Vapid::base64UrlDecode($parts[1]), true)['aud'])->toBe('https://push.example.com:8443');
});

test('a public key that does not belong to the private key leaves Web Push off', function () {
    configureVapid();
    config(['webpush.public_key' => Vapid::generateKeys()['public_key']]);

    expect(app(Vapid::class)->isConfigured())->toBeFalse();
});

test('the key command prints a usable pair without writing it anywhere', function () {
    $this->artisan('webpush:vapid')
        ->expectsOutputToContain('VAPID_PUBLIC_KEY=')
        ->expectsOutputToContain('VAPID_PRIVATE_KEY=')
        ->assertSuccessful();
});

test('guests cannot subscribe a browser', function () {
    $this->post(route('notifications.push-subscriptions.store'), ['endpoint' => PUSH_ENDPOINT])
        ->assertRedirect(route('login'));

    expect(PushSubscription::query()->count())->toBe(0);
});

test('a user subscribes their browser storing only the endpoint and a short device label', function () {
    $user = User::factory()->agent()->create();

    $this->actingAs($user)
        ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36')
        ->post(route('notifications.push-subscriptions.store'), ['endpoint' => PUSH_ENDPOINT, 'keys' => ['p256dh' => 'x', 'auth' => 'y']])
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Notificaciones push activadas en este navegador.');

    $subscription = PushSubscription::query()->sole();

    expect($subscription->only(['user_id', 'endpoint', 'endpoint_hash', 'user_agent']))->toBe([
        'user_id' => $user->id,
        'endpoint' => PUSH_ENDPOINT,
        'endpoint_hash' => hash('sha256', PUSH_ENDPOINT),
        'user_agent' => 'Chrome en Windows',
    ]);
});

test('registering a browser again moves it to whoever registered it last', function () {
    $previous = User::factory()->agent()->create();
    PushSubscription::factory()->for($previous)->endpoint(PUSH_ENDPOINT)->create();
    $current = User::factory()->agent()->create();

    $this->actingAs($current)->post(route('notifications.push-subscriptions.store'), ['endpoint' => PUSH_ENDPOINT])->assertRedirect();

    expect(PushSubscription::query()->sole()->user_id)->toBe($current->id);
});

test('only endpoints of known push services over HTTPS are accepted', function (string $endpoint) {
    $user = User::factory()->agent()->create();

    $this->actingAs($user)
        ->post(route('notifications.push-subscriptions.store'), ['endpoint' => $endpoint])
        ->assertSessionHasErrors('endpoint');

    expect(PushSubscription::query()->count())->toBe(0);
})->with([
    'internal address' => 'https://169.254.169.254/latest/meta-data',
    'plain http' => 'http://fcm.googleapis.com/fcm/send/abc',
    'look-alike host' => 'https://fcm.googleapis.com.attacker.test/send',
    'credentials in the URL' => 'https://user:secret@fcm.googleapis.com/fcm/send/abc',
]);

test('the endpoint validation explains the rejection', function () {
    $this->actingAs(User::factory()->agent()->create())
        ->post(route('notifications.push-subscriptions.store'), ['endpoint' => 'https://example.com/push'])
        ->assertSessionHasErrors(['endpoint' => 'El navegador no usa un servicio de notificaciones push admitido.']);
});

test('a user keeps at most ten browsers, dropping the oldest', function () {
    $user = User::factory()->agent()->create();
    $oldest = PushSubscription::factory()->for($user)->create(['updated_at' => now()->subDays(5)]);
    PushSubscription::factory()->for($user)->count(9)->create(['updated_at' => now()->subDay()]);

    $this->actingAs($user)->post(route('notifications.push-subscriptions.store'), ['endpoint' => PUSH_ENDPOINT])->assertRedirect();

    expect(PushSubscription::query()->where('user_id', $user->id)->count())->toBe(10)
        ->and(PushSubscription::query()->find($oldest->id))->toBeNull();
});

test('a user removes their own device but gets 404 for somebody else\'s', function () {
    $user = User::factory()->agent()->create();
    $own = PushSubscription::factory()->for($user)->create();
    $foreign = PushSubscription::factory()->create();

    $this->actingAs($user)->delete(route('notifications.push-subscriptions.destroy', $foreign->id))->assertNotFound();
    $this->actingAs($user)->delete(route('notifications.push-subscriptions.destroy', $own->id))->assertRedirect();

    expect(PushSubscription::query()->pluck('id')->all())->toBe([$foreign->id]);
});

test('the preferences page lists only my devices and the public key when configured', function () {
    $keys = configureVapid();
    $user = User::factory()->agent()->create();
    $own = PushSubscription::factory()->for($user)->create();
    PushSubscription::factory()->create();

    $this->actingAs($user)->get(route('notifications.preferences.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('push.public_key', $keys['public_key'])
            ->has('push.subscriptions', 1)
            ->where('push.subscriptions.0', [
                'id' => $own->id,
                'endpoint_hash' => $own->endpoint_hash,
                'user_agent' => 'Chrome en Windows',
                'created_at' => $own->created_at->toIso8601String(),
            ]));
});

test('without VAPID keys the page offers no push and nothing is sent', function () {
    config(['webpush.public_key' => null, 'webpush.private_key' => null]);
    Http::preventStrayRequests();
    $user = pushRecipient();

    $this->actingAs($user)->get(route('notifications.preferences.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('push.public_key', null));

    notifyAccessChange($user);

    Http::assertNothingSent();
});

test('a new in-app notification wakes the browser with an empty, signed push', function () {
    configureVapid();
    Http::preventStrayRequests();
    Http::fake([PUSH_ENDPOINT => Http::response(null, 201)]);
    $user = pushRecipient();

    notifyAccessChange($user);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === PUSH_ENDPOINT
        && $request->method() === 'POST'
        && $request->body() === ''
        && $request->header('Content-Length') === ['0']
        && $request->header('TTL') === ['3600']
        && $request->header('Urgency') === ['normal']
        && ! $request->hasHeader('Content-Encoding')
        && str_starts_with($request->header('Authorization')[0], 'vapid t='));
});

test('a burst of notifications sends one push per throttle window', function () {
    configureVapid();
    Http::preventStrayRequests();
    Http::fake([PUSH_ENDPOINT => Http::response(null, 201)]);
    $user = pushRecipient();

    notifyAccessChange($user, '1');
    notifyAccessChange($user, '2');
    Http::assertSentCount(1);

    $this->travel(61)->seconds();
    notifyAccessChange($user, '3');

    Http::assertSentCount(2);
});

test('a Web Push failure is reported without breaking the in-app notification', function () {
    Exceptions::fake();
    Http::preventStrayRequests();
    $user = pushRecipient();
    $this->mock(Vapid::class)->shouldReceive('isConfigured')->andThrow(new RuntimeException('OpenSSL no disponible.'));

    notifyAccessChange($user);

    expect($user->notifications()->count())->toBe(1);
    Exceptions::assertReported(RuntimeException::class);
    Http::assertNothingSent();
});

test('no push is sent when the user keeps "Navegador" off for the kind', function () {
    configureVapid();
    Http::preventStrayRequests();
    $user = pushRecipient(browser: false);

    notifyAccessChange($user);

    Http::assertNothingSent();
});

test('an expired subscription is deleted', function (int $status) {
    configureVapid();
    Http::preventStrayRequests();
    Http::fake([PUSH_ENDPOINT => Http::response(null, $status)]);
    $user = pushRecipient();

    notifyAccessChange($user);

    expect(PushSubscription::query()->count())->toBe(0);
})->with([404, 410]);

test('a busy push service is retried later and the subscription is kept', function (int $status, array $headers, int $delay) {
    configureVapid();
    Http::preventStrayRequests();
    Http::fake([PUSH_ENDPOINT => Http::response(null, $status, $headers)]);
    $subscription = PushSubscription::factory()->endpoint(PUSH_ENDPOINT)->create();

    $job = (new SendWebPush($subscription->id))->withFakeQueueInteractions();
    $job->handle(app(Vapid::class));

    $job->assertReleased($delay);
    expect(PushSubscription::query()->find($subscription->id))->not->toBeNull();
})->with([
    'throttled with Retry-After' => [429, ['Retry-After' => '120'], 120],
    'server error' => [503, [], 15],
]);

test('deleting a user removes their push subscriptions', function () {
    $user = pushRecipient();
    $other = PushSubscription::factory()->create();

    app(DeleteUser::class)->handle($user, User::factory()->superAdministrator()->create());

    expect(PushSubscription::query()->pluck('id')->all())->toBe([$other->id]);
});

test('a subscription of a deactivated user is dropped instead of pushed', function () {
    configureVapid();
    Http::preventStrayRequests();
    $subscription = PushSubscription::factory()->for(User::factory()->inactive())->endpoint(PUSH_ENDPOINT)->create();

    (new SendWebPush($subscription->id))->handle(app(Vapid::class));

    Http::assertNothingSent();
    expect(PushSubscription::query()->count())->toBe(0);
});

test('pruning deletes subscriptions that were not used for a long time', function () {
    $stale = PushSubscription::factory()->create(['updated_at' => now()->subDays(121)]);
    $recent = PushSubscription::factory()->create(['updated_at' => now()->subDays(30)]);

    $result = app(PruneNotifications::class)->handle();

    expect($result['push_subscriptions'])->toBe(1)
        ->and(PushSubscription::query()->pluck('id')->all())->toBe([$recent->id])
        ->and(PushSubscription::query()->find($stale->id))->toBeNull();
});

test('the preflight reports the state of the VAPID keys', function () {
    config(['webpush.public_key' => null, 'webpush.private_key' => null]);
    expect(app(Preflight::class)->webPush()->status)->toBe(HealthStatus::Skipped);

    configureVapid();
    config(['app.url' => 'https://chat.example.com']);
    expect(app(Preflight::class)->webPush()->status)->toBe(HealthStatus::Ok);

    config(['webpush.public_key' => Vapid::generateKeys()['public_key']]);
    expect(app(Preflight::class)->webPush()->status)->toBe(HealthStatus::Warning);
});
