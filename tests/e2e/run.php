<?php

/*
| Runs the browser scenarios of tests/e2e against the testing database.
|
|   php tests/e2e/run.php [scenario.mjs]      (default: increment-b.mjs)
|
| It never creates databases: it uses chatmanager_testing (.env.testing),
| holds the same exclusive lock as the Pest suite (so both never run at the
| same time), rebuilds it, starts its own local server, runs Chromium
| through Playwright and stops the server when done.
|
| Environment:
|   E2E_PORT              local server port (default 8020)
|   E2E_SERVER_WORKERS    PHP built-in server workers (default 4)
|   NODE_BINARY           Node.js executable (default "node")
|   PLAYWRIGHT_MODULE     where to load Playwright from (default "playwright")
|   E2E_SCREENSHOTS       optional directory for screenshots
*/

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\TestDatabase;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';

$root = dirname(__DIR__, 2);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$scenario = __DIR__.'/'.basename($argv[1] ?? 'increment-b.mjs');
$port = (int) (getenv('E2E_PORT') ?: 8020);
$baseUrl = "http://127.0.0.1:{$port}";

$fail = function (string $message): never {
    fwrite(STDERR, $message.PHP_EOL);
    exit(1);
};

if (! is_file($scenario)) {
    $fail("Scenario [{$scenario}] not found.");
}

$connection = config('database.connections.'.config('database.default'));

try {
    TestDatabase::guard((string) $connection['database']);
    TestDatabase::lock($connection);
} catch (RuntimeException $exception) {
    $fail($exception->getMessage());
}

if (@fsockopen('127.0.0.1', $port, timeout: 0.5) !== false) {
    $fail("Port {$port} is already in use; stop that server or set E2E_PORT.");
}

echo "Rebuilding [{$connection['database']}]...".PHP_EOL;

if (Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]) !== 0) {
    $fail(Artisan::output());
}

$environment = [...getenv(), 'APP_ENV' => 'testing', 'APP_URL' => $baseUrl, 'E2E_BASE_URL' => $baseUrl];

passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/increment-b-seed.php').' setup > /dev/null', $seeded);

if ($seeded !== 0) {
    $fail('Seeding the scenario data failed.');
}

$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", $root.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
    $root.'/public',
    [...$environment, 'PHP_CLI_SERVER_WORKERS' => (string) (getenv('E2E_SERVER_WORKERS') ?: 4)],
);

$stopServer = function () use ($server): void {
    $pid = proc_get_status($server)['pid'];

    // The built-in server does not stop its workers when it is terminated.
    exec('pkill -TERM -P '.(int) $pid);
    proc_terminate($server);
    proc_close($server);
};

for ($attempt = 0; $attempt < 50 && @fsockopen('127.0.0.1', $port, timeout: 0.2) === false; $attempt++) {
    usleep(200_000);
}

$node = proc_open([getenv('NODE_BINARY') ?: 'node', $scenario], [1 => STDOUT, 2 => STDERR], $pipes, $root, $environment);
$status = proc_close($node);

$stopServer();

exit($status);
