<?php

use App\Models\User;
use App\Support\ConcurrencySafeRateLimiter;
use App\Support\ReadCommittedTransaction;
use Illuminate\Cache\ArrayStore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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
