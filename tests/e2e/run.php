<?php

/*
| Runs the browser scenarios of tests/e2e against the testing database.
|
|   php tests/e2e/run.php [scenario.mjs]      (default: increment-b.mjs)
|
| Scenarios: increment-b.mjs, corrections.mjs, finalizacion.mjs,
| realtime.mjs, estabilizacion.mjs, bandeja.mjs, and since phase 4
| chat-movil.mjs (chat on phones, light and dark), bandeja-general.mjs
| (general inbox, WhatsApp + web chat, toasts) and widget.mjs (the web chat
| widget on cross-site host pages; needs E2E_HOST_PORT), and since phase 5
| fase5.mjs (personal items, filter menu, delivery statuses, supervision,
| attachments, responsive matrix and PWA). The command line
| of each one is in its header and in docs/pruebas.md §6.
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
|   PLAYWRIGHT_MODULE     where to load Playwright from (default "playwright";
|                         it is not a project dependency, so point it at an
|                         installed package). Both are checked before the
|                         database is touched; a broken Node.js or a missing
|                         Playwright/Chromium stops the run with a clear error.
|   E2E_SCREENSHOTS       optional directory for screenshots (also keeps the
|                         output of the web server and Reverb started here)
|   E2E_REVERB=1          also start a Reverb server for real-time scenarios,
|                         with throwaway credentials generated for the run
|   E2E_REVERB_PORT       Reverb port (default 8081)
|   E2E_UPLOAD_MAX_FILESIZE, E2E_POST_MAX_SIZE
|                         PHP upload limits of the local web server
|                         (-d upload_max_filesize / -d post_max_size), e.g.
|                         100M and 110M as in production; unset, the
|                         server keeps the php.ini values (subidas.mjs
|                         runs with and without them)
|   E2E_MEMORY_LIMIT      -d memory_limit of the local web server (only to
|                         prove streaming under a given limit)
|   E2E_HOST_PORT         also serve tests/e2e/hosts/ (the widget's host
|                         pages) on this port; the scenarios open them as
|                         http://tienda.test:{port} and
|                         http://intruso.test:{port} (Chromium maps both
|                         names to 127.0.0.1, see launchBrowser in lib.mjs)
|
| The widget loader (public/widget/v1/chat-manager.js) is not versioned: it
| is built with `npm run build:widget` when it is missing.
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

// Node.js and Playwright are checked before the database is locked and
// rebuilt: Playwright is not a project dependency, so a missing or stale
// PLAYWRIGHT_MODULE must fail here with a clear message.
$nodeBinary = getenv('NODE_BINARY') ?: 'node';
$playwrightModule = getenv('PLAYWRIGHT_MODULE') ?: 'playwright';

/** @return array{0: int, 1: string} */
$runNode = function (string ...$arguments) use ($nodeBinary): array {
    $process = @proc_open([$nodeBinary, ...array_values($arguments)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__);

    if (! is_resource($process)) {
        return [127, ''];
    }

    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), trim($output)];
};

[$nodeStatus, $nodeOutput] = $runNode('--version');

if ($nodeStatus !== 0) {
    $fail("Node.js [{$nodeBinary}] does not run: ".($nodeOutput !== '' ? strtok($nodeOutput, PHP_EOL) : 'not found').PHP_EOL
        .'Set NODE_BINARY to a working Node.js executable (for example NODE_BINARY=/usr/local/bin/node).');
}

[$playwrightStatus, $playwrightOutput] = $runNode(
    '-e',
    'const { chromium } = require(process.argv[1]); if (!require("fs").existsSync(chromium.executablePath())) { console.error("Chromium is not installed for this Playwright: " + chromium.executablePath()); process.exit(3); }',
    $playwrightModule,
);

if ($playwrightStatus !== 0) {
    $reason = preg_match('/^(?:\w*Error\b.*|Chromium is not installed.*)$/m', $playwrightOutput, $match) === 1 ? $match[0] : strtok($playwrightOutput, PHP_EOL);
    $fail("Playwright could not be loaded from [{$playwrightModule}] with Node.js {$nodeOutput}: {$reason}".PHP_EOL
        .'Playwright is not a project dependency: set PLAYWRIGHT_MODULE to the directory of an installed "playwright" package'
        .' (for example $HOME/.npm/_npx/<hash>/node_modules/playwright, created by `npx playwright@1.63.0 --version`)'
        .' and make sure its Chromium is installed (`npx playwright@1.63.0 install chromium`).');
}

// The widget loader is a build artifact (npm run build includes it).
if (! is_file($root.'/public/widget/v1/chat-manager.js')) {
    echo 'Building the widget loader (npm run build:widget)...'.PHP_EOL;
    passthru('cd '.escapeshellarg($root).' && npm run build:widget', $built);

    if ($built !== 0 || ! is_file($root.'/public/widget/v1/chat-manager.js')) {
        $fail('The widget loader could not be built: run `npm run build` (or `npm run build:widget`).');
    }
}

$hostPort = (int) (getenv('E2E_HOST_PORT') ?: 0);

if ($hostPort > 0 && @fsockopen('127.0.0.1', $hostPort, timeout: 0.5) !== false) {
    $fail("Port {$hostPort} is already in use; stop that server or set E2E_HOST_PORT.");
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

$environment = [...getenv(), 'APP_ENV' => 'testing', 'APP_URL' => $baseUrl, 'E2E_BASE_URL' => $baseUrl, 'E2E_PHP_BINARY' => PHP_BINARY];

// With E2E_SCREENSHOTS the output of the servers started here is kept there.
$shots = getenv('E2E_SCREENSHOTS') ?: null;

if ($shots !== null && ! is_dir($shots)) {
    mkdir($shots, 0777, true);
}

$serverLog = fn (string $name): array => ['file', $shots !== null ? $shots.'/'.$name.'.log' : '/dev/null', 'a'];

$reverb = null;

if (getenv('E2E_REVERB') === '1') {
    $reverbPort = (int) (getenv('E2E_REVERB_PORT') ?: 8081);

    if (@fsockopen('127.0.0.1', $reverbPort, timeout: 0.5) !== false) {
        $fail("Port {$reverbPort} is already in use; stop that server or set E2E_REVERB_PORT.");
    }

    // Credentials only live for this run; real-time listeners run inline
    // (sync) because no queue worker is started for the scenarios.
    $environment = [
        ...$environment,
        'BROADCAST_CONNECTION' => 'reverb',
        'REVERB_APP_ID' => (string) random_int(100000, 999999),
        'REVERB_APP_KEY' => bin2hex(random_bytes(10)),
        'REVERB_APP_SECRET' => bin2hex(random_bytes(16)),
        'REVERB_HOST' => '127.0.0.1',
        'REVERB_PORT' => (string) $reverbPort,
        'REVERB_SCHEME' => 'http',
        'REVERB_SERVER_HOST' => '127.0.0.1',
        'REVERB_SERVER_PORT' => (string) $reverbPort,
        'REVERB_ALLOWED_ORIGINS' => '127.0.0.1,localhost',
        'REALTIME_QUEUE_CONNECTION' => 'sync',
        'E2E_REVERB_PORT' => (string) $reverbPort,
    ];

    $reverb = proc_open(
        [PHP_BINARY, $root.'/artisan', 'reverb:start', '--host=127.0.0.1', "--port={$reverbPort}"],
        [1 => $serverLog('reverb-runner'), 2 => $serverLog('reverb-runner')],
        $pipes,
        $root,
        $environment,
    );

    for ($attempt = 0; $attempt < 50 && @fsockopen('127.0.0.1', $reverbPort, timeout: 0.2) === false; $attempt++) {
        usleep(200_000);
    }

    if (@fsockopen('127.0.0.1', $reverbPort, timeout: 0.5) === false) {
        if (is_resource($reverb)) {
            exec('pkill -TERM -P '.(int) proc_get_status($reverb)['pid']);
            proc_terminate($reverb);
            proc_close($reverb);
        }

        $fail("Reverb did not start on port {$reverbPort}".($shots !== null ? " (see {$shots}/reverb-runner.log)." : '; set E2E_SCREENSHOTS to keep its output.'));
    }
}

$stopReverb = function () use ($reverb): void {
    if (! is_resource($reverb)) {
        return;
    }

    exec('pkill -TERM -P '.(int) proc_get_status($reverb)['pid']);
    proc_terminate($reverb);
    proc_close($reverb);
};

passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/increment-b-seed.php').' setup > /dev/null', $seeded);

if ($seeded !== 0) {
    $stopReverb();
    $fail('Seeding the scenario data failed.');
}

// Upload limits of the web server process (php.ini values when unset).
$iniOverrides = [];

foreach (['E2E_UPLOAD_MAX_FILESIZE' => 'upload_max_filesize', 'E2E_POST_MAX_SIZE' => 'post_max_size', 'E2E_MEMORY_LIMIT' => 'memory_limit'] as $variable => $directive) {
    $value = getenv($variable);

    if (is_string($value) && preg_match('/^-?\d+[KMG]?$/i', $value) === 1) {
        array_push($iniOverrides, '-d', "{$directive}={$value}");
    }
}

$server = proc_open(
    [PHP_BINARY, ...$iniOverrides, '-S', "127.0.0.1:{$port}", $root.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'],
    [1 => $serverLog('php-server'), 2 => $serverLog('php-server')],
    $pipes,
    $root.'/public',
    [...$environment, 'PHP_CLI_SERVER_WORKERS' => (string) (getenv('E2E_SERVER_WORKERS') ?: 4)],
);

if (! is_resource($server)) {
    $stopReverb();
    $fail('The local web server could not be started.');
}

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

if (@fsockopen('127.0.0.1', $port, timeout: 0.5) === false) {
    $stopServer();
    $stopReverb();
    $fail("The local web server did not start on port {$port}".($shots !== null ? " (see {$shots}/php-server.log)." : '.'));
}

// The widget's host pages: plain static files from another "site".
$hosts = null;

if ($hostPort > 0) {
    $hosts = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$hostPort}", '-t', __DIR__.'/hosts'],
        [1 => $serverLog('host-server'), 2 => $serverLog('host-server')],
        $pipes,
        __DIR__.'/hosts',
    );

    for ($attempt = 0; $attempt < 50 && @fsockopen('127.0.0.1', $hostPort, timeout: 0.2) === false; $attempt++) {
        usleep(200_000);
    }

    if (! is_resource($hosts) || @fsockopen('127.0.0.1', $hostPort, timeout: 0.5) === false) {
        $stopServer();
        $stopReverb();
        $fail("The host pages server did not start on port {$hostPort}.");
    }
}

$stopHosts = function () use ($hosts): void {
    if (is_resource($hosts)) {
        proc_terminate($hosts);
        proc_close($hosts);
    }
};

$node = proc_open([$nodeBinary, $scenario], [1 => STDOUT, 2 => STDERR], $pipes, $root, $environment);
$status = is_resource($node) ? proc_close($node) : 1;

$stopHosts();
$stopServer();
$stopReverb();

exit($status);
