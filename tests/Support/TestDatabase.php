<?php

namespace Tests\Support;

use Illuminate\Support\Facades\ParallelTesting;
use PDO;
use RuntimeException;

/**
 * The single database that automated tests may rebuild (by default
 * "chatmanager_testing", see config/database.php "testing_database").
 *
 * Every destructive test run (Pest, the browser scenarios) goes through it:
 *
 * - guard() refuses any other database, so the application database, a
 *   production one or ad hoc copies ("*_verify", "*_e2e_testing"...) are
 *   never refreshed and new databases are never needed;
 * - lock() takes an exclusive MariaDB lock for the whole process, so two
 *   runs sharing the database never interfere: the second one waits up to
 *   TEST_DB_LOCK_WAIT seconds (default 0, fail at once) and then stops with
 *   an explanation instead of corrupting the first one's data.
 */
final class TestDatabase
{
    /**
     * Connection holding the lock until the process ends.
     */
    private static ?PDO $holder = null;

    public static function guard(string $database): void
    {
        $expected = (string) config('database.testing_database');

        if ($expected === '' || $database !== $expected) {
            throw new RuntimeException("Refusing to rebuild [{$database}]: automated tests only run on the testing database [{$expected}].");
        }

        if (ParallelTesting::token() !== false) {
            throw new RuntimeException('Parallel testing is disabled: it would create one extra database per process. Run the suite without --parallel.');
        }
    }

    /**
     * @param  array<string, mixed>  $connection  Configuration of the testing connection.
     */
    public static function lock(array $connection): void
    {
        if (self::$holder !== null) {
            return;
        }

        $wait = max(0, (int) (getenv('TEST_DB_LOCK_WAIT') ?: 0));

        self::$holder = self::tryLock($connection, $wait) ?? throw new RuntimeException(
            "Another run (Pest or the browser scenarios) is using [{$connection['database']}]. Wait for it to finish and try again.",
        );
    }

    /**
     * The connection that now holds the lock, or null when another process
     * still has it after waiting up to $waitSeconds.
     *
     * @param  array<string, mixed>  $connection
     */
    public static function tryLock(array $connection, int $waitSeconds = 0): ?PDO
    {
        $socket = (string) ($connection['unix_socket'] ?? '');
        $dsn = $socket !== ''
            ? "mysql:unix_socket={$socket}"
            : sprintf('mysql:host=%s;port=%s', $connection['host'], $connection['port']);

        $pdo = new PDO($dsn, (string) $connection['username'], (string) $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $statement = $pdo->query('SELECT GET_LOCK('.$pdo->quote(self::lockName((string) $connection['database'])).', '.max(0, $waitSeconds).')');

        return $statement !== false && (int) $statement->fetchColumn() === 1 ? $pdo : null;
    }

    private static function lockName(string $database): string
    {
        return substr("chat-manager-tests:{$database}", 0, 64);
    }
}
