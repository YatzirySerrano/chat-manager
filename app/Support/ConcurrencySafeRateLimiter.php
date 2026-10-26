<?php

namespace App\Support;

use Illuminate\Cache\RateLimiter;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
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
 *
 * Such a deadlock is retried a bounded number of times after a short random
 * pause; if it persists the hit is let through (fail open) and logged. Only
 * use this limiter where letting a request through is harmless because
 * every request is authorized on its own (the real-time channel
 * authorization), never for sign in or other abuse-sensitive limits.
 */
class ConcurrencySafeRateLimiter extends RateLimiter
{
    use DetectsConcurrencyErrors;

    /** Attempts of one increment before failing open. */
    private const ATTEMPTS = 3;

    /**
     * @param  string  $key
     * @param  \DateTimeInterface|\DateInterval|int  $decaySeconds
     * @param  int  $amount
     */
    public function increment($key, $decaySeconds = 60, $amount = 1): int
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return parent::increment($key, $decaySeconds, $amount);
            } catch (QueryException $exception) {
                if ($exception instanceof UniqueConstraintViolationException || ! $this->causedByConcurrencyError($exception)) {
                    throw $exception;
                }

                Log::warning(ReadCommittedTransaction::RETRY_LOG_MESSAGE, [
                    'operation' => 'rate-limiter',
                    'attempt' => $attempt,
                    'error_code' => $exception->errorInfo[1] ?? $exception->getCode(),
                ]);

                if ($attempt >= self::ATTEMPTS) {
                    return $this->attemptsFailingOpen($key);
                }

                usleep(random_int(10_000, 40_000) * $attempt);
            }
        }
    }

    /**
     * The counter as it stands, or 0 when even reading it fails: the hit is
     * not counted, so the request is let through.
     */
    private function attemptsFailingOpen(string $key): int
    {
        try {
            return (int) $this->attempts($key);
        } catch (Throwable $exception) {
            report($exception);

            return 0;
        }
    }
}
