<?php

/*
| Entry point of a concurrent test process (see ConcurrentProcess). It boots
| the application like a queue worker or a web request would, refuses to
| run against any database other than the testing one, waits for GO and
| runs one scenario of scenarios.php.
|
| Usage: php worker.php <scenario> <base64 json arguments>
*/

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$say = function (string $line): void {
    fwrite(STDOUT, $line."\n");
    fflush(STDOUT);
};

$await = function (string $word): void {
    while (($line = fgets(STDIN)) !== false) {
        if (trim($line) === $word) {
            return;
        }
    }

    exit(4);
};

$expected = (string) config('database.testing_database');
$default = DB::getDefaultConnection();

if (! app()->environment('testing') || $expected === '' || config("database.connections.{$default}.database") !== $expected || DB::connection()->getDriverName() !== 'mariadb') {
    fwrite(STDERR, "Refusing to run: concurrent test processes only use the MariaDB testing database.\n");

    exit(3);
}

// A test may shorten how long this process waits for a row lock, to prove
// that an operation is not blocked by another one.
$lockWait = getenv('CONCURRENCY_LOCK_WAIT');

if (is_string($lockWait) && ctype_digit($lockWait)) {
    DB::statement('SET SESSION innodb_lock_wait_timeout = '.(int) $lockWait);
}

/** @var array<string, Closure(array<string, mixed>, Closure(string): void, Closure(string): void): mixed> $scenarios */
$scenarios = require __DIR__.'/scenarios.php';
$name = $argv[1] ?? '';
$arguments = json_decode((string) base64_decode($argv[2] ?? ''), true);

if (! isset($scenarios[$name]) || ! is_array($arguments)) {
    fwrite(STDERR, "Unknown scenario [{$name}] or invalid arguments.\n");

    exit(2);
}

// A test that gives up on this process asks where it was stuck.
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    $printStack = function (): void {
        fwrite(STDERR, new Exception('Interrupted by the test')->getTraceAsString()."\nEND OF STACK\n");
    };
    pcntl_signal(SIGUSR1, $printStack);
    // Watchdog: a scenario never runs for long; a stuck one tells where it is.
    pcntl_signal(SIGALRM, function () use ($printStack): void {
        $printStack();

        exit(5);
    });
    pcntl_alarm(max(5, (int) (getenv('CONCURRENCY_WATCHDOG') ?: 50)));
}

$say('READY');
$await('GO');
$say('STARTED');

try {
    $value = $scenarios[$name]($arguments, $say, $await);
    $say('RESULT '.json_encode(['ok' => true, 'value' => $value]));
} catch (Throwable $exception) {
    $say('RESULT '.json_encode([
        'ok' => false,
        'error' => $exception::class,
        'message' => Str::limit($exception->getMessage(), 300),
    ]));
}
