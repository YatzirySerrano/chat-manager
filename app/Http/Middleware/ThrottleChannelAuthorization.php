<?php

namespace App\Http\Middleware;

use App\Support\ConcurrencySafeRateLimiter;
use Illuminate\Contracts\Cache\Factory as CacheFactory;

/**
 * "throttle:attempts,minutes" (per route) for the real-time channel
 * authorization (POST /broadcasting/auth), counted by a limiter that
 * retries the database cache store's deadlocks and finally lets the request
 * through instead of answering 500 (ConcurrencySafeRateLimiter).
 *
 * The real-time client authorizes every channel of a user in parallel when
 * it connects or resubscribes (a departments supervisor holds two channels
 * per department), so all of them hit the same counter row at once. Failing
 * open is harmless here: the endpoint requires a signed in, verified, active
 * user and every channel is authorized again by its own rule; the limit only
 * stops reconnection storms. Sign in and other limits keep the strict
 * limiter.
 *
 * Only "attempts,minutes" parameters: named limiters (RateLimiter::for) are
 * registered on the application's limiter, not on this one.
 */
class ThrottleChannelAuthorization extends ThrottleRequestsPerRoute
{
    public function __construct(CacheFactory $cache)
    {
        $store = config('cache.limiter');

        parent::__construct(new ConcurrencySafeRateLimiter($cache->store(is_string($store) ? $store : null)));
    }
}
