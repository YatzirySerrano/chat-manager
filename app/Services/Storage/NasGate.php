<?php

namespace App\Services\Storage;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Admission control of the NAS share: every operation that touches it
 * (copies of the media-sync workers, fetches into the local cache, web
 * requests that stream a file only on the NAS) takes one of a few slots
 * first, so the Synology never sees more than a fixed number of concurrent
 * transfers whatever the traffic. Workers wait their turn (their job is
 * released back to its queue); a web request that finds no free slot gets a
 * quick 503 with Retry-After instead of piling up on the share.
 *
 * Slots are cache locks with a TTL, so a process killed mid transfer frees
 * its slot by itself. Transfers are also paced to a maximum speed
 * (throttle()), leaving bandwidth for the rest of the NAS users.
 */
class NasGate
{
    private const PREFIX = 'storage:nas-gate:';

    /**
     * A free slot of the given pool ("sync", "fetch" or "http"), or null when all
     * of them are in use.
     */
    public function acquire(string $disk, string $pool, int $seconds): ?Lock
    {
        $slots = max(1, (int) config("attachments.storage.local_first.nas_slots.{$pool}", $pool === 'http' ? 3 : 1));

        try {
            $store = Cache::getStore();

            if (! $store instanceof LockProvider) {
                return null;
            }

            foreach ($this->order($slots) as $slot) {
                $lock = $store->lock(self::PREFIX."{$disk}:{$pool}:{$slot}", max(10, $seconds));

                if ($lock->get()) {
                    return $lock;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    public function release(?Lock $lock): void
    {
        try {
            $lock?->release();
        } catch (Throwable) {
            // The TTL frees it.
        }
    }

    /**
     * Sleeps as needed so a transfer that moved $bytes since $startedAt
     * stays under the configured speed (0 = unlimited).
     */
    public function throttle(int $bytes, float $startedAt): void
    {
        $limit = max(0, (int) config('attachments.storage.local_first.nas_max_bytes_per_second', 0));

        if ($limit === 0) {
            return;
        }

        $ahead = $bytes / $limit - (microtime(true) - $startedAt);

        if ($ahead > 0) {
            usleep((int) min(5_000_000, $ahead * 1_000_000));
        }
    }

    /**
     * Slots in a random order, so processes do not all fight for slot 0.
     *
     * @return list<int>
     */
    private function order(int $slots): array
    {
        $order = range(0, $slots - 1);
        shuffle($order);

        return $order;
    }
}
