<?php

use App\Http\Middleware\ThrottleChannelAuthorization;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use App\Support\ConcurrencySafeRateLimiter;
use App\Support\ReadCommittedTransaction;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\QueryException;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Realtime\RealtimeTestSupport;

/*
| Every channel of a user is authorized in parallel when the real-time
| client connects, so all of them hit the same rate-limit counter. On the
| database cache store that collides into InnoDB deadlocks; the channel
| authorization must still answer (retrying, finally letting the request
| through), never a 500 that leaves the channel silent for the session.
*/

/**
 * A limiter store whose increments deadlock the given number of times.
 */
function deadlockingLimiterStore(int $failures, string $message = 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'): ArrayStore
{
    $store = new class extends ArrayStore
    {
        public int $failures = 0;

        public string $message = '';

        public int $calls = 0;

        public function increment($key, $value = 1)
        {
            $this->calls++;

            if ($this->failures > 0) {
                $this->failures--;

                throw new QueryException('mariadb', 'select * from `cache` where `key` = ? for update', [], new Exception($this->message));
            }

            return parent::increment($key, $value);
        }
    };
    $store->failures = $failures;
    $store->message = $message;

    Cache::extend('deadlocking', fn () => Cache::repository($store));
    config(['cache.stores.deadlocking' => ['driver' => 'deadlocking'], 'cache.limiter' => 'deadlocking']);

    return $store;
}

/**
 * A limiter store whose reads (the counter and its timer, read by
 * tooManyAttempts(), attempts() and availableIn()) fail the given number of times.
 */
function failingReadsLimiterStore(int $failures, string $message = 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'): ArrayStore
{
    $store = new class extends ArrayStore
    {
        public int $failures = 0;

        public string $message = '';

        public int $calls = 0;

        public function get($key)
        {
            $this->calls++;

            if ($this->failures > 0) {
                $this->failures--;

                throw new QueryException('mariadb', 'select * from `cache` where `key` in (?)', [], new Exception($this->message));
            }

            return parent::get($key);
        }

        public function increment($key, $value = 1)
        {
            $this->calls++;

            return parent::increment($key, $value);
        }
    };
    $store->failures = $failures;
    $store->message = $message;

    Cache::extend('failing-reads', fn () => Cache::repository($store));
    config(['cache.stores.failing-reads' => ['driver' => 'failing-reads'], 'cache.limiter' => 'failing-reads']);

    return $store;
}

beforeEach(fn () => RealtimeTestSupport::useReverb());

test('a deadlock on the rate-limit counter is retried and the channel is still authorized', function () {
    $store = deadlockingLimiterStore(2);
    $user = User::factory()->agent()->create();
    Log::spy();

    $this->actingAs($user)->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-users.{$user->id}"])
        ->assertOk()
        ->assertJsonStructure(['auth']);

    expect($store->calls)->toBe(3);
    Log::shouldHaveReceived('warning')->with(ReadCommittedTransaction::RETRY_LOG_MESSAGE, Mockery::on(fn (array $context): bool => $context['operation'] === 'rate-limiter'))->twice();
});

test('a deadlock that persists lets the authorization through instead of failing', function () {
    deadlockingLimiterStore(100);
    $user = User::factory()->agent()->create();

    $this->actingAs($user)->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-users.{$user->id}"])
        ->assertOk();

    // Failing open never skips the channel's own rule.
    $other = User::factory()->agent()->create();
    $this->actingAs($user)->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-users.{$other->id}"])
        ->assertForbidden();
});

test('channel authorizations keep their limit per user', function () {
    $user = User::factory()->agent()->create();
    $payload = ['socket_id' => '1234.5678', 'channel_name' => "private-users.{$user->id}"];

    for ($request = 1; $request <= 180; $request++) {
        $this->actingAs($user)->postJson('/broadcasting/auth', $payload)->assertOk();
    }

    $this->actingAs($user)->postJson('/broadcasting/auth', $payload)->assertTooManyRequests();
});

test('only concurrency errors are absorbed by the channel limiter', function () {
    deadlockingLimiterStore(1, 'SQLSTATE[42S02]: Base table or view not found');
    $limiter = new ConcurrencySafeRateLimiter(Cache::store('deadlocking'));

    expect(fn () => $limiter->hit('clave'))->toThrow(QueryException::class);
});

test('a hit that stays uncounted is logged apart, without the user, and counted for the day', function () {
    deadlockingLimiterStore(100);
    $user = User::factory()->agent()->create();
    Log::spy();

    $this->actingAs($user)->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-users.{$user->id}"])
        ->assertOk();

    Log::shouldHaveReceived('warning')->with(ConcurrencySafeRateLimiter::UNCOUNTED_LOG_MESSAGE, Mockery::on(
        fn (array $context): bool => $context['operation'] === 'hit'
            && array_keys($context) === ['operation', 'key_fingerprint', 'error_code']
            && strlen($context['key_fingerprint']) === 12,
    ))->once();
    expect(ConcurrencySafeRateLimiter::uncountedToday())->toBe(1);
});

test('deadlocks reading the counter are retried, then let through without skipping any authorization', function () {
    $store = failingReadsLimiterStore(100);
    $sales = Department::factory()->create();
    $agent = User::factory()->agent()->inDepartments($sales)->create();
    $own = Conversation::factory()->inDepartment($sales)->assignedTo($agent)->create();
    $foreign = Conversation::factory()->inDepartment(Department::factory()->create())->create();
    $other = User::factory()->agent()->create();
    Log::spy();

    $this->actingAs($agent)->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-users.{$agent->id}"])
        ->assertOk();
    $this->actingAs($agent)->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-conversations.{$own->id}"])
        ->assertOk();

    // The channel's own rule still decides: another user's channel and a
    // conversation outside the agent's reach stay forbidden.
    $this->actingAs($agent)->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-users.{$other->id}"])
        ->assertForbidden();
    $this->actingAs($agent)->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-conversations.{$foreign->id}"])
        ->assertForbidden();

    expect($store->calls)->toBeGreaterThan(0);
    Log::shouldHaveReceived('warning')->with(ConcurrencySafeRateLimiter::UNCOUNTED_LOG_MESSAGE, Mockery::on(fn (array $context): bool => $context['operation'] === 'read'))->atLeast()->once();
});

test('a deadlock reading the counter once is retried and the limit still counts', function () {
    $store = failingReadsLimiterStore(1);
    $user = User::factory()->agent()->create();
    Log::spy();

    $this->actingAs($user)->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-users.{$user->id}"])
        ->assertOk()
        ->assertHeader('X-RateLimit-Remaining', '179');

    Log::shouldNotHaveReceived('warning', [ConcurrencySafeRateLimiter::UNCOUNTED_LOG_MESSAGE, Mockery::any()]);
    expect(ConcurrencySafeRateLimiter::uncountedToday())->toBe(0);
});

test('without a signed in user the failing limiter is never reached', function () {
    $store = failingReadsLimiterStore(100);
    $user = User::factory()->agent()->create();

    $this->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-users.{$user->id}"])
        ->assertUnauthorized();

    expect($store->calls)->toBe(0);
});

test('a limiter store that fails for another reason fails closed', function () {
    failingReadsLimiterStore(100, 'SQLSTATE[HY000] [2002] Connection refused');
    $user = User::factory()->agent()->create();

    $this->actingAs($user)->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-users.{$user->id}"])
        ->assertServerError()
        ->assertJsonMissingPath('auth');
});

test('only the channel authorization uses the limiter that may fail open', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (IlluminateRoute $route): bool => collect($route->gatherMiddleware())->contains(fn (mixed $middleware): bool => is_string($middleware)
            && (str_starts_with($middleware, 'throttle.channels') || str_starts_with($middleware, ThrottleChannelAuthorization::class))))
        ->map(fn (IlluminateRoute $route): string => $route->uri())
        ->values()
        ->all();

    expect($routes)->toBe(['broadcasting/auth'])
        ->and(app(RateLimiter::class))->not->toBeInstanceOf(ConcurrencySafeRateLimiter::class);
});
