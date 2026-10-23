<?php

namespace App\Support;

use Closure;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Runs a short write transaction of the inbound and notification pipelines
 * at READ COMMITTED, retrying deadlocks a bounded number of times.
 *
 * At REPEATABLE READ a locking lookup of a row that does not exist yet (a new
 * sender's phone, a contact without an unclosed conversation, an aggregation
 * key without an unread notification) takes a gap lock, and every new row of
 * those indexes lands in the same gap: concurrent workers inserting
 * different rows deadlocked on each other's insert intention. READ COMMITTED
 * takes no gap locks, so only workers racing for the same unique key wait for
 * each other, and the loser gets a unique violation, rethrown untouched for
 * the caller to reuse the winner's row.
 *
 * `SET TRANSACTION ISOLATION LEVEL` only applies to the next transaction of
 * the session, so it is issued again right before each attempt starts. It is
 * only used when this is the outermost transaction: inside another one (a
 * caller's transaction, or a test wrapped by RefreshDatabase) the isolation
 * level cannot change and the callback simply joins it at the session level.
 *
 * Deadlocks left (delete-marked unique entries, rollbacks of a third worker)
 * are recovered by InnoDB rolling one transaction back; they are retried
 * after a short random pause, so the same pair does not collide again, and
 * each retry is logged with the operation that suffered it so a load run
 * can attribute them (tests/load/run.php counts RETRY_LOG_MESSAGE).
 */
class ReadCommittedTransaction
{
    use DetectsConcurrencyErrors;

    /** Log message of every retried deadlock or lock wait. */
    public const RETRY_LOG_MESSAGE = 'Interbloqueo de base de datos reintentado.';

    /** Attempts when MariaDB resolves a deadlock between workers. */
    private const ATTEMPTS = 3;

    /**
     * @template TReturn
     *
     * @param  string  $operation  Short identifier of the work, for the retry log (no data).
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function run(string $operation, Closure $callback): mixed
    {
        if (DB::transactionLevel() > 0) {
            return DB::transaction($callback, self::ATTEMPTS);
        }

        for ($attempt = 1; ; $attempt++) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');

            try {
                return DB::transaction($callback);
            } catch (QueryException $exception) {
                if ($exception instanceof UniqueConstraintViolationException || $attempt >= self::ATTEMPTS || ! $this->causedByConcurrencyError($exception)) {
                    throw $exception;
                }

                Log::warning(self::RETRY_LOG_MESSAGE, [
                    'operation' => $operation,
                    'attempt' => $attempt,
                    'error_code' => $exception->errorInfo[1] ?? $exception->getCode(),
                ]);

                usleep(random_int(5_000, 40_000) * $attempt);
            }
        }
    }
}
