<?php

namespace App\Services\Storage;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Circuit breaker of the remote copies to a mounted disk: after
 * breaker_failures consecutive failed copies the media-sync workers stop
 * touching the share for breaker_seconds (a struggling NAS is not hammered
 * by retries), then copies test it again.
 */
class RemoteCircuit
{
    private const PREFIX = 'storage:remote-circuit:';

    public function allows(string $disk): bool
    {
        return $this->openFor($disk) === null;
    }

    /**
     * Seconds until the circuit closes again, or null when it is closed.
     */
    public function openFor(string $disk): ?int
    {
        try {
            $openUntil = Cache::get(self::PREFIX.'open:'.$disk);
        } catch (Throwable) {
            return null;
        }

        $now = now()->getTimestamp();

        return is_int($openUntil) && $openUntil > $now ? $openUntil - $now : null;
    }

    public function success(string $disk): void
    {
        try {
            Cache::forget(self::PREFIX.'failures:'.$disk);
            Cache::forget(self::PREFIX.'open:'.$disk);
        } catch (Throwable) {
            // Best effort.
        }
    }

    public function failure(string $disk): void
    {
        try {
            $key = self::PREFIX.'failures:'.$disk;
            $failures = (int) Cache::get($key, 0) + 1;

            if ($failures >= max(1, (int) config('attachments.storage.local_first.breaker_failures', 5))) {
                $seconds = max(30, (int) config('attachments.storage.local_first.breaker_seconds', 300));
                Cache::put(self::PREFIX.'open:'.$disk, now()->getTimestamp() + $seconds, $seconds + 60);
                Cache::forget($key);
                Log::warning('Media sync circuit opened.', ['disk' => $disk, 'seconds' => $seconds]);

                return;
            }

            Cache::put($key, $failures, 3600);
        } catch (Throwable) {
            // Best effort.
        }
    }
}
