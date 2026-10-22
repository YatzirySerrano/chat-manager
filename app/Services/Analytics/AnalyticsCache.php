<?php

namespace App\Services\Analytics;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Short-lived cache of computed figures, keyed by the viewer's reach and the
 * filters. Every key embeds a version that is bumped when metrics change,
 * at most once every few seconds, so a burst of messages costs one
 * invalidation and figures are never older than the TTL.
 */
class AnalyticsCache
{
    private const VERSION_KEY = 'analytics:version';

    private const BUMP_THROTTLE_KEY = 'analytics:version-bumped';

    /**
     * Minimum seconds between two automatic invalidations.
     */
    public const BUMP_THROTTLE_SECONDS = 10;

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    public function remember(string $key, int $seconds, Closure $callback): mixed
    {
        return Cache::remember("analytics:{$this->version()}:{$key}", $seconds, $callback);
    }

    /**
     * Invalidate after a metric changed (throttled).
     */
    public function touch(): void
    {
        if (Cache::add(self::BUMP_THROTTLE_KEY, true, self::BUMP_THROTTLE_SECONDS)) {
            $this->flush();
        }
    }

    /**
     * Invalidate every cached figure now.
     */
    public function flush(): void
    {
        Cache::forever(self::VERSION_KEY, $this->version() + 1);
    }

    private function version(): int
    {
        $version = Cache::get(self::VERSION_KEY, 0);

        return is_int($version) ? $version : 0;
    }
}
