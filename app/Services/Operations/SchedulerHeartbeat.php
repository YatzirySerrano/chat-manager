<?php

namespace App\Services\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

/**
 * Proof that the scheduler is running: `ops:heartbeat` stores the time of
 * each run in the cache (shared by every server when the store is Redis or
 * the database), and the health checks read its age.
 */
class SchedulerHeartbeat
{
    public function __construct(private Repository $cache) {}

    public function beat(): CarbonImmutable
    {
        $now = CarbonImmutable::now();

        // Kept without expiry: a missing key means "never ran", an old one "stopped".
        $this->cache->forever($this->key(), $now->getTimestamp());

        return $now;
    }

    /**
     * Time of the newest beat, or null when there is none (never ran or the
     * cache cannot be read).
     */
    public function lastBeatAt(): ?CarbonImmutable
    {
        try {
            $timestamp = $this->cache->get($this->key());
        } catch (Throwable) {
            return null;
        }

        return is_numeric($timestamp) ? CarbonImmutable::createFromTimestamp((int) $timestamp) : null;
    }

    public function ageInSeconds(): ?int
    {
        $last = $this->lastBeatAt();

        return $last === null ? null : max(0, (int) $last->diffInSeconds(CarbonImmutable::now(), true));
    }

    public function isStale(): bool
    {
        $age = $this->ageInSeconds();

        return $age === null || $age > $this->staleAfterSeconds();
    }

    public function staleAfterSeconds(): int
    {
        return max(60, (int) config('operations.scheduler.stale_after_seconds', 300));
    }

    private function key(): string
    {
        return (string) config('operations.scheduler.heartbeat_key', 'operations:scheduler-heartbeat');
    }
}
