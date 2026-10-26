<?php

namespace App\Support;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A rate limiter whose counter survives the database cache store's own
 * deadlocks. With the "database" limiter store, `increment()` runs two
 * `INSERT IGNORE` (timer and counter) and then a `SELECT … FOR UPDATE`
 * on the same row; parallel hits on one key (every channel authorization
 * of a user arriving at once when the real-time client connects or
 * resubscribes) meet the shared lock of one statement with the exclusive
 * lock of the other and InnoDB rolls one of them back (1213, SQLSTATE 40001).
 * The reads of the counter (tooManyAttempts(), attempts(), availableIn())
 * can meet the same locks.
 *
 * Each case, decided apart:
 *
 * - a concurrency error (deadlock, lock wait timeout) on a hit or a read is
 *   retried a bounded number of times after a short random pause;
 * - if it persists, the request is let through uncounted (fail open). The
 *   limit only stops reconnection storms; authentication, the active
 *   account and every channel's own rule are enforced before and apart
 *   from it, and answering 429 or 500 to the parallel authorizations of a
 *   connecting client would leave its channels silent and make it retry,
 *   feeding the storm. Every uncounted request is logged
 *   (UNCOUNTED_LOG_MESSAGE, without the user or the key) and counted per
 *   day (uncountedToday()) so a persisting problem is visible;
 * - any other failure of the store (unreachable, missing table) is not
 *   absorbed: the request fails closed (500, reported as an error).
 *
 * Only use this limiter where letting a request through is harmless because
 * every request is authorized on its own (the real-time channel
 * authorization), never for sign in or other abuse-sensitive limits: those
 * keep the application's strict limiter.
 */
class ConcurrencySafeRateLimiter extends RateLimiter
{
    use DetectsConcurrencyErrors;

    public const UNCOUNTED_LOG_MESSAGE = 'Límite de autorización de canales no contado por interbloqueo; la solicitud continúa.';

    /** Attempts of one operation before failing open. */
    private const ATTEMPTS = 3;

    private const UNCOUNTED_KEY = 'rate-limiter:channels:uncounted:';

    /**
     * @param  string  $key
     * @param  \DateTimeInterface|\DateInterval|int  $decaySeconds
     * @param  int  $amount
     */
    public function increment($key, $decaySeconds = 60, $amount = 1): int
    {
        return $this->retrying('hit', $key, fn (): int => parent::increment($key, $decaySeconds, $amount), fn (): int => $this->attemptsFailingOpen($key));
    }

    /**
     * @param  string  $key
     * @param  int  $maxAttempts
     */
    public function tooManyAttempts($key, $maxAttempts): bool
    {
        return $this->retrying('read', $key, function () use ($key, $maxAttempts): bool {
            if ((int) parent::attempts($key) >= $maxAttempts) {
                if ($this->cache->has($this->cleanRateLimiterKey($key).':timer')) {
                    return true;
                }

                $this->resetAttempts($key);
            }

            return false;
        }, fn (): bool => false);
    }

    /**
     * @param  string  $key
     */
    public function attempts($key): int
    {
        return $this->retrying('read', $key, fn (): int => (int) parent::attempts($key), fn (): int => 0);
    }

    /**
     * @param  string  $key
     */
    public function availableIn($key): int
    {
        return $this->retrying('read', $key, fn (): int => (int) parent::availableIn($key), fn (): int => 60);
    }

    /**
     * Requests let through uncounted today (all channel limiter keys).
     */
    public static function uncountedToday(): int
    {
        try {
            return (int) Cache::get(self::UNCOUNTED_KEY.now()->toDateString(), 0);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @template TResult
     *
     * @param  'hit'|'read'  $operation
     * @param  Closure(): TResult  $callback
     * @param  Closure(): TResult  $failOpen
     * @return TResult
     */
    private function retrying(string $operation, string $key, Closure $callback, Closure $failOpen): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $callback();
            } catch (QueryException $exception) {
                if ($exception instanceof UniqueConstraintViolationException || ! $this->causedByConcurrencyError($exception)) {
                    throw $exception;
                }

                $errorCode = $exception->errorInfo[1] ?? $exception->getCode();

                Log::warning(ReadCommittedTransaction::RETRY_LOG_MESSAGE, [
                    'operation' => 'rate-limiter',
                    'attempt' => $attempt,
                    'error_code' => $errorCode,
                ]);

                if ($attempt >= self::ATTEMPTS) {
                    $this->recordUncounted($operation, $key, $errorCode);

                    return $failOpen();
                }

                usleep(random_int(10_000, 40_000) * $attempt);
            }
        }
    }

    /**
     * A dedicated warning (no user, only a short fingerprint of the
     * already hashed key) and the daily counter. Neither may break the
     * request: the counter shares the store that is failing.
     */
    private function recordUncounted(string $operation, string $key, mixed $errorCode): void
    {
        Log::warning(self::UNCOUNTED_LOG_MESSAGE, [
            'operation' => $operation,
            'key_fingerprint' => substr(hash('sha256', $key), 0, 12),
            'error_code' => $errorCode,
        ]);

        try {
            $counter = self::UNCOUNTED_KEY.now()->toDateString();
            Cache::add($counter, 0, now()->addDays(2));
            Cache::increment($counter);
        } catch (Throwable) {
            // The log line above already records it.
        }
    }

    /**
     * The counter as it stands, or 0 when even reading it fails: the hit is
     * not counted, so the request is let through.
     */
    private function attemptsFailingOpen(string $key): int
    {
        try {
            return (int) parent::attempts($key);
        } catch (Throwable $exception) {
            report($exception);

            return 0;
        }
    }
}
