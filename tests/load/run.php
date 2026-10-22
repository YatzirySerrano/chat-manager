<?php

/*
| Reproducible load and resilience battery (docs/rendimiento-y-carga.md).
|
|   php tests/load/run.php <scenario[:n]>[,<scenario[:n]>...]
|
|   scenarios: profile | webhooks[:total] | agents[:count] | websocket[:connections] | export
|   e.g.       php tests/load/run.php profile,webhooks:2000
|              php tests/load/run.php agents:10,agents:25
|
| What it does, in order:
| 1. refuses any database but chatmanager_testing and takes the same
|    exclusive lock as the Pest suite (TEST_DB_LOCK_WAIT seconds), so the
|    battery never runs while tests use the database (and vice versa);
| 2. rebuilds chatmanager_testing (migrate:fresh --seed) and loads the
|    synthetic dataset (tests/load/seed.php) plus the real report metrics
|    (analytics:rebuild);
| 3. starts its own processes on its own ports: the web server (Apache
|    httpd event MPM + PHP-FPM static pool when both exist, else PHP's
|    built-in multi-worker server), the queue workers of the production
|    layout (deploy/supervisor) and Reverb; every child gets a private
|    storage directory (logs, sessions files, exports) under the output
|    directory, so the development app's storage is never touched;
| 4. runs each scenario (tests/load/scenarios/*.mjs on Node's built-in
|    fetch/WebSocket) while sampling once per second CPU and memory of the
|    processes (ps), the queue backlog and the InnoDB lock counters;
| 5. waits for the queues to drain, verifies the end state in the database
|    (no lost or duplicated messages, one winner per contended accept,
|    forward-only statuses, failed jobs, deadlocks) and writes one JSON
|    report per scenario plus summary.json to the output directory;
| 6. stops everything it started (also on Ctrl+C / errors).
|
| WhatsApp is SIMULATED: webhooks are signed with throwaway secrets of
| fixture connections and replies go through WHATSAPP_TRANSPORT=simulated.
| Nothing is sent to or received from Meta.
|
| Environment (all optional):
|   E2E_PORT / LOAD_PORT        HTTP port (default 8050)
|   LOAD_FPM_PORT               PHP-FPM port (default 8052)
|   E2E_REVERB_PORT / LOAD_REVERB_PORT  Reverb port (default 8111)
|   LOAD_SERVER                 fpm | builtin (default fpm when httpd and php-fpm exist)
|   LOAD_PHP_WORKERS            FPM children / built-in server workers (default 8)
|   LOAD_WORKERS                queue workers, "queues:count;..." (default
|                               "webhooks,messages:4;media:1;notifications,default:2;exports:1")
|   LOAD_CONVERSATIONS          historical conversations (default 10000)
|   LOAD_SEED_AGENTS            agent users in the dataset (default 100)
|   LOAD_OUTPUT                 output directory (default: system temp dir)
|   LOAD_SCENARIO_TIMEOUT       hard limit per scenario in seconds (default 300)
|   LOAD_DRAIN_TIMEOUT          max wait for the queues after a scenario (default 180)
|   NODE_BINARY                 Node.js 22+ executable (default "node")
|   plus the LOAD_* knobs documented at the top of each scenario.
*/

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestDatabase;

$root = dirname(__DIR__, 2);
$scenarioArgument = $argv[1] ?? '';

if ($scenarioArgument === '' || in_array($scenarioArgument, ['-h', '--help'], true)) {
    fwrite(STDERR, "Usage: php tests/load/run.php profile,webhooks:2000,agents:10,websocket:200,export\n");
    exit(1);
}

$knob = fn (string $name, string $default): string => (string) (getenv($name) !== false && getenv($name) !== '' ? getenv($name) : $default);

$port = (int) $knob('LOAD_PORT', $knob('E2E_PORT', '8050'));
$fpmPort = (int) $knob('LOAD_FPM_PORT', '8052');
$reverbPort = (int) $knob('LOAD_REVERB_PORT', $knob('E2E_REVERB_PORT', '8111'));
$phpWorkers = max(1, (int) $knob('LOAD_PHP_WORKERS', '8'));
$fpmBinary = $knob('LOAD_FPM_BINARY', '/opt/homebrew/sbin/php-fpm');
$httpdBinary = $knob('LOAD_HTTPD_BINARY', '/usr/sbin/httpd');
$serverKind = $knob('LOAD_SERVER', is_executable($fpmBinary) && is_executable($httpdBinary) ? 'fpm' : 'builtin');
$baseUrl = "http://127.0.0.1:{$port}";
$outDir = rtrim($knob('LOAD_OUTPUT', sys_get_temp_dir().'/chat-manager-load/'.date('Ymd-His')), '/');
$scenarioTimeout = (int) $knob('LOAD_SCENARIO_TIMEOUT', '300');
$drainTimeout = (int) $knob('LOAD_DRAIN_TIMEOUT', '180');

$storage = "{$outDir}/storage";

foreach (['app/private', 'app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'framework/testing', 'logs'] as $directory) {
    @mkdir("{$storage}/{$directory}", 0700, true);
}

$reverbCredentials = [
    'REVERB_APP_ID' => (string) random_int(100000, 999999),
    'REVERB_APP_KEY' => bin2hex(random_bytes(10)),
    'REVERB_APP_SECRET' => bin2hex(random_bytes(16)),
];

/*
| One environment for every process of the run (this one included, so the
| in-process profiler sees the same configuration as the servers).
*/
$environment = [
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'APP_URL' => $baseUrl,
    'LARAVEL_STORAGE_PATH' => $storage,
    'LOG_CHANNEL' => 'stack',
    'LOG_STACK' => 'single',
    'LOG_LEVEL' => 'info',
    'QUEUE_CONNECTION' => 'database',
    'CACHE_STORE' => 'database',
    'SESSION_DRIVER' => 'database',
    'MAIL_MAILER' => 'array',
    'BCRYPT_ROUNDS' => '4',
    'INERTIA_SSR_ENABLED' => 'false',
    'WHATSAPP_TRANSPORT' => 'simulated',
    'EXPORTS_QUEUE' => 'exports',
    'BROADCAST_CONNECTION' => 'reverb',
    ...$reverbCredentials,
    'REVERB_HOST' => '127.0.0.1',
    'REVERB_PORT' => (string) $reverbPort,
    'REVERB_SCHEME' => 'http',
    'REVERB_SERVER_HOST' => '127.0.0.1',
    'REVERB_SERVER_PORT' => (string) $reverbPort,
    'REVERB_ALLOWED_ORIGINS' => '127.0.0.1,localhost',
];

foreach ([
    'WHATSAPP_WEBHOOK_RATE_LIMIT', 'WHATSAPP_SIMULATE_STATUSES', 'DB_QUEUE_RETRY_AFTER', 'REVERB_APP_RATE_LIMITING_ENABLED',
] as $passThrough) {
    if (getenv($passThrough) !== false) {
        $environment[$passThrough] = (string) getenv($passThrough);
    }
}

foreach ($environment as $name => $value) {
    putenv("{$name}={$value}");
    $_ENV[$name] = $_SERVER[$name] = $value;
}

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$say = function (string $message): void {
    fwrite(STDOUT, '['.date('H:i:s').'] '.$message.PHP_EOL);
};

$fail = function (string $message): never {
    fwrite(STDERR, $message.PHP_EOL);
    exit(1);
};

$connection = config('database.connections.'.config('database.default'));

try {
    TestDatabase::guard((string) $connection['database']);
    TestDatabase::lock($connection);
} catch (RuntimeException $exception) {
    $fail($exception->getMessage());
}

foreach (array_unique([$port, $reverbPort, ...($serverKind === 'fpm' ? [$fpmPort] : [])]) as $busy) {
    if (@fsockopen('127.0.0.1', $busy, timeout: 0.5) !== false) {
        $fail("Port {$busy} is already in use; choose other LOAD_* ports.");
    }
}

$say("Output: {$outDir}");
$say("Rebuilding [{$connection['database']}] (testing database only)...");
$timings = [];
$started = microtime(true);

if (Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]) !== 0) {
    $fail(Artisan::output());
}

$timings['migrate_fresh_seconds'] = round(microtime(true) - $started, 2);

$childEnvironment = [...getenv(), ...$environment];
$password = bin2hex(random_bytes(12));
$manifestPath = "{$outDir}/manifest.json";
$seedOptions = json_encode([
    'conversations' => (int) $knob('LOAD_CONVERSATIONS', '10000'),
    'agents' => (int) $knob('LOAD_SEED_AGENTS', '100'),
]);

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $extra
 * @return array{int, string, string}
 */
$runPhp = function (array $arguments, array $extra = []) use ($root, $childEnvironment): array {
    $process = proc_open(array_values([PHP_BINARY, ...$arguments]), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, [...$childEnvironment, ...$extra]);

    if (! is_resource($process)) {
        return [1, '', 'Could not start '.implode(' ', $arguments)];
    }

    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), (string) $output, (string) $errors];
};

$say('Loading the synthetic dataset...');
$started = microtime(true);
[$status, $output, $errors] = $runPhp(['-d', 'memory_limit=1G', $root.'/tests/load/seed.php', $manifestPath], ['LOAD_PASSWORD' => $password, 'LOAD_SEED_OPTIONS' => (string) $seedOptions]);

if ($status !== 0 || ! is_file($manifestPath)) {
    $fail("Seeding failed:\n".mb_substr($errors.$output, 0, 3000));
}

$timings['seed_seconds'] = round(microtime(true) - $started, 2);
$say('Dataset: '.trim($output));

$say('Computing report metrics (analytics:rebuild)...');
$started = microtime(true);
[$status, , $errors] = $runPhp(['-d', 'memory_limit=1G', $root.'/artisan', 'analytics:rebuild']);

if ($status !== 0) {
    $fail("analytics:rebuild failed:\n{$errors}");
}

$timings['analytics_rebuild_seconds'] = round(microtime(true) - $started, 2);
$timings['conversation_metrics_rows'] = DB::table('conversation_metrics')->count();

// The rebuild queued nothing that matters for the runs; start from empty queues.
DB::table('jobs')->delete();

$manifest = json_decode((string) file_get_contents($manifestPath), true);

/* ------------------------------------------------------------- processes */

/** @var array<string, array{process: resource, role: string}> $processes */
$processes = [];

/**
 * @param  list<string>  $command
 * @param  array<string, string>  $extra
 */
$start = function (string $name, string $role, array $command, string $cwd, array $extra = []) use (&$processes, $childEnvironment, $outDir): void {
    $log = "{$outDir}/{$name}.log";
    $process = proc_open(array_values($command), [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $cwd, [...$childEnvironment, ...$extra]);

    if (! is_resource($process)) {
        throw new RuntimeException("Could not start {$name}.");
    }

    $processes[$name] = ['process' => $process, 'role' => $role];
};

$stopAll = function () use (&$processes): void {
    foreach (array_reverse($processes, true) as $name => $entry) {
        $status = proc_get_status($entry['process']);

        if ($status['running']) {
            // Children first (FPM / httpd / built-in server workers), then the parent.
            exec('pkill -TERM -P '.(int) $status['pid'].' 2>/dev/null');
            proc_terminate($entry['process'], 15);
        }
    }

    $deadline = microtime(true) + 10;

    foreach ($processes as $name => $entry) {
        while (proc_get_status($entry['process'])['running'] && microtime(true) < $deadline) {
            usleep(100_000);
        }

        if (proc_get_status($entry['process'])['running']) {
            exec('pkill -KILL -P '.(int) proc_get_status($entry['process'])['pid'].' 2>/dev/null');
            proc_terminate($entry['process'], 9);
        }

        proc_close($entry['process']);
    }

    $processes = [];
};

register_shutdown_function($stopAll);

if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, function () use ($stopAll): void {
        $stopAll();
        exit(130);
    });
    pcntl_signal(SIGTERM, function () use ($stopAll): void {
        $stopAll();
        exit(143);
    });
}

$waitForPort = function (int $waitPort, float $seconds = 15.0): bool {
    $deadline = microtime(true) + $seconds;

    while (microtime(true) < $deadline) {
        if (@fsockopen('127.0.0.1', $waitPort, timeout: 0.2) !== false) {
            return true;
        }

        usleep(150_000);
    }

    return false;
};

if ($serverKind === 'fpm') {
    file_put_contents("{$outDir}/php-fpm.conf", implode("\n", [
        '[global]',
        "pid = {$outDir}/php-fpm.pid",
        "error_log = {$outDir}/php-fpm-error.log",
        'daemonize = no',
        '[load]',
        "listen = 127.0.0.1:{$fpmPort}",
        'pm = static',
        "pm.max_children = {$phpWorkers}",
        'pm.max_requests = 0',
        'listen.backlog = 4096',
        'clear_env = no',
        'catch_workers_output = yes',
        'request_terminate_timeout = 120',
        'php_admin_value[memory_limit] = 512M',
        'php_admin_value[opcache.validate_timestamps] = 1',
        'php_admin_value[opcache.revalidate_freq] = 2',
        '',
    ]));

    $modules = ['mpm_event', 'unixd', 'authz_core', 'dir', 'mime', 'log_config', 'rewrite', 'proxy', 'proxy_fcgi'];
    // Enough threads for reconnect storms (hundreds of simultaneous
    // requests): they wait for a free FPM child instead of being refused.
    $threads = 100;
    file_put_contents("{$outDir}/httpd.conf", implode("\n", [
        'ServerRoot "/usr"',
        "Listen 127.0.0.1:{$port}",
        ...array_map(fn (string $module): string => "LoadModule {$module}_module libexec/apache2/mod_{$module}.so", $modules),
        'ServerName 127.0.0.1',
        "PidFile \"{$outDir}/httpd.pid\"",
        "DefaultRuntimeDir \"{$outDir}\"",
        "Mutex file:{$outDir} default",
        "ErrorLog \"{$outDir}/httpd-error.log\"",
        'LogLevel warn',
        'TypesConfig /private/etc/apache2/mime.types',
        'KeepAlive On',
        'MaxKeepAliveRequests 0',
        'KeepAliveTimeout 30',
        'ServerLimit 8',
        'StartServers 2',
        "ThreadsPerChild {$threads}",
        'MaxRequestWorkers '.($threads * 8),
        'ThreadLimit '.$threads,
        "DocumentRoot \"{$root}/public\"",
        'DirectoryIndex index.php',
        "<Directory \"{$root}/public\">",
        '    AllowOverride None',
        '    Require all granted',
        '    RewriteEngine On',
        '    RewriteCond %{REQUEST_FILENAME} !-f',
        '    RewriteRule ^ index.php [L]',
        '</Directory>',
        '<FilesMatch "\.php$">',
        "    SetHandler \"proxy:fcgi://127.0.0.1:{$fpmPort}\"",
        '</FilesMatch>',
        'ProxyTimeout 120',
        '',
    ]));

    $start('php-fpm', 'web', [$fpmBinary, '-F', '-y', "{$outDir}/php-fpm.conf", '-p', $outDir], $root);
    $waitForPort($fpmPort) || $fail("PHP-FPM did not start; see {$outDir}/php-fpm-error.log");
    $start('httpd', 'web', [$httpdBinary, '-f', "{$outDir}/httpd.conf", '-DFOREGROUND'], $root);
    $serverDescription = 'Apache httpd 2.4 (event MPM) + PHP-FPM '.PHP_VERSION." (pm=static, {$phpWorkers} procesos, OPcache activo)";
} else {
    $start('php-server', 'web', [PHP_BINARY, '-d', 'opcache.enable_cli=1', '-S', "127.0.0.1:{$port}", $root.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'], $root.'/public', ['PHP_CLI_SERVER_WORKERS' => (string) $phpWorkers]);
    $serverDescription = 'Servidor integrado de PHP '.PHP_VERSION." (PHP_CLI_SERVER_WORKERS={$phpWorkers}, OPcache CLI activo)";
}

$waitForPort($port) || $fail("The web server did not start; see {$outDir}/*.log");

$workerLayout = $knob('LOAD_WORKERS', 'webhooks,messages:4;media:1;notifications,default:2;exports:1');
$workerDescription = [];

foreach (array_filter(explode(';', $workerLayout)) as $index => $definition) {
    [$queues, $count] = array_pad(explode(':', $definition), 2, '1');
    $timeout = str_contains($queues, 'exports') ? 660 : 150;

    for ($worker = 1; $worker <= (int) $count; $worker++) {
        $start("worker-{$index}-{$worker}", 'workers', [PHP_BINARY, $root.'/artisan', 'queue:work', 'database', "--queue={$queues}", '--sleep=1', '--tries=3', '--backoff=10', "--timeout={$timeout}", '--memory=512'], $root);
    }

    $workerDescription[] = "{$count} × queue:work --queue={$queues}";
}

$start('reverb', 'reverb', [PHP_BINARY, $root.'/artisan', 'reverb:start', '--host=127.0.0.1', "--port={$reverbPort}"], $root);
$waitForPort($reverbPort) || $fail("Reverb did not start; see {$outDir}/reverb.log");

// Warm up (OPcache, route/view compilation) before measuring anything.
for ($warm = 0; $warm < $phpWorkers * 2; $warm++) {
    @file_get_contents("{$baseUrl}/login");
}

$say("Web: {$serverDescription}; workers: ".implode(', ', $workerDescription).'; Reverb on '.$reverbPort);

/* --------------------------------------------------------------- sampling */

$mariadbPid = (int) trim((string) shell_exec('pgrep -x mariadbd | head -1'));
$databaseName = (string) $connection['database'];

$globalStatus = function (): array {
    $values = [];

    foreach (DB::select("SHOW GLOBAL STATUS WHERE Variable_name IN ('Innodb_row_lock_waits','Innodb_row_lock_time','Innodb_row_lock_time_max','Innodb_row_lock_current_waits','Innodb_deadlocks','Questions','Slow_queries','Threads_connected','Max_used_connections','Innodb_buffer_pool_reads','Innodb_buffer_pool_read_requests')") as $row) {
        $values[$row->Variable_name] = (int) $row->Value;
    }

    return $values;
};

$parseCpuTime = function (string $time): float {
    $days = 0;

    if (str_contains($time, '-')) {
        [$days, $time] = explode('-', $time, 2);
    }

    $parts = array_reverse(explode(':', $time));

    return (int) $days * 86400 + (float) $parts[0] + (int) ($parts[1] ?? 0) * 60 + (int) ($parts[2] ?? 0) * 3600;
};

/**
 * CPU seconds and RSS per role of every process of the run (+ MariaDB).
 *
 * @return array<string, array{cpu: float, rss_kb: int, processes: int}>
 */
$snapshotProcesses = function (array $extraRoots) use (&$processes, $mariadbPid, $parseCpuTime): array {
    $table = [];

    foreach (explode("\n", trim((string) shell_exec('ps -A -o pid=,ppid=,time=,rss='))) as $line) {
        $fields = preg_split('/\s+/', trim($line)) ?: [];

        if (count($fields) >= 4) {
            $table[(int) $fields[0]] = ['ppid' => (int) $fields[1], 'cpu' => $parseCpuTime($fields[2]), 'rss' => (int) $fields[3]];
        }
    }

    $roots = $extraRoots;

    foreach ($processes as $entry) {
        $roots[proc_get_status($entry['process'])['pid']] = $entry['role'];
    }

    if ($mariadbPid > 0) {
        $roots[$mariadbPid] = 'mariadb';
    }

    $children = [];

    foreach ($table as $pid => $row) {
        $children[$row['ppid']][] = $pid;
    }

    $groups = [];

    foreach ($roots as $pid => $role) {
        $stack = [$pid];

        while ($stack !== []) {
            $current = array_pop($stack);

            if (! isset($table[$current])) {
                continue;
            }

            $groups[$role] ??= ['cpu' => 0.0, 'rss_kb' => 0, 'processes' => 0];
            $groups[$role]['cpu'] += $table[$current]['cpu'];
            $groups[$role]['rss_kb'] += $table[$current]['rss'];
            $groups[$role]['processes']++;

            foreach ($children[$current] ?? [] as $child) {
                $stack[] = $child;
            }
        }
    }

    return $groups;
};

/**
 * Runs one Node scenario while sampling, then waits for the queues.
 *
 * @return array<string, mixed>
 */
$runScenario = function (string $script, array $extra, string $label) use ($root, $childEnvironment, $outDir, $scenarioTimeout, $drainTimeout, $snapshotProcesses, $globalStatus, $say, $knob): array {
    $resultPath = "{$outDir}/{$label}.result.json";
    @unlink($resultPath);
    $baseline = [
        'messages' => (int) DB::table('messages')->max('id'),
        'webhook_events' => (int) DB::table('webhook_events')->max('id'),
        'failed_jobs' => (int) DB::table('failed_jobs')->max('id'),
        'conversations' => (int) DB::table('conversations')->max('id'),
        'contacts' => (int) DB::table('contacts')->max('id'),
        'conversation_events' => (int) DB::table('conversation_events')->max('id'),
        'data_transfers' => (int) DB::table('data_transfers')->max('id'),
        'started_at' => now()->toDateTimeString(),
    ];
    // Current queue and assignments, so a scenario never reuses what an
    // earlier scenario of the same run already accepted or closed.
    $statePath = "{$outDir}/{$label}.state.json";
    $pending = [];

    foreach (DB::table('conversations')->where('status', 'pending')->whereNull('assigned_user_id')->whereNotNull('department_id')->orderBy('id')->get(['id', 'department_id']) as $row) {
        $pending[$row->department_id][] = $row->id;
    }

    $open = [];

    foreach (DB::table('conversations')->where('status', 'open')->where('last_inbound_at', '>', now()->subHours(23))->whereNotNull('assigned_user_id')->orderBy('id')->get(['id', 'assigned_user_id']) as $row) {
        $open[$row->assigned_user_id][] = $row->id;
    }

    file_put_contents($statePath, json_encode(['pending_by_department' => $pending, 'open_by_agent' => $open]));
    $extra['LOAD_STATE'] = $statePath;
    $statusBefore = $globalStatus();
    $logPath = storage_path('logs/laravel.log');
    $logOffset = is_file($logPath) ? (int) filesize($logPath) : 0;

    $node = proc_open(
        [$knob('NODE_BINARY', 'node'), "{$root}/tests/load/scenarios/{$script}"],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', "{$outDir}/{$label}.node.log", 'a'], 2 => ['file', "{$outDir}/{$label}.node.log", 'a']],
        $pipes,
        $root,
        [...$childEnvironment, ...$extra, 'LOAD_RESULT' => $resultPath, 'LOAD_LABEL' => $label],
    );

    if (! is_resource($node)) {
        throw new RuntimeException("Could not start the scenario {$label}.");
    }

    $nodePid = proc_get_status($node)['pid'];

    $samples = [];
    $previous = $snapshotProcesses([$nodePid => 'node']);
    $previousAt = microtime(true);
    $startedAt = $previousAt;
    $nodeSeconds = null;
    $drainStartedAt = null;
    $drained = false;

    while (true) {
        usleep(1_000_000);
        $now = microtime(true);
        $running = proc_get_status($node)['running'];

        if ($running && $now - $startedAt > $scenarioTimeout) {
            $say("Scenario {$label} exceeded {$scenarioTimeout}s; stopping it.");
            proc_terminate($node, 15);
        }

        $current = $snapshotProcesses($running ? [$nodePid => 'node'] : []);
        $cpu = [];

        foreach ($current as $role => $group) {
            $delta = $group['cpu'] - ($previous[$role]['cpu'] ?? $group['cpu']);
            $cpu[$role] = ['cpu_pct' => round(max(0, $delta) / max(0.001, $now - $previousAt) * 100, 1), 'rss_mb' => round($group['rss_kb'] / 1024, 1), 'processes' => $group['processes']];
        }

        $previous = $current;
        $previousAt = $now;

        $queues = [];

        foreach (DB::table('jobs')->selectRaw('queue, COUNT(*) AS total, SUM(reserved_at IS NOT NULL) AS reserved, SUM(available_at <= UNIX_TIMESTAMP()) AS ready')->groupBy('queue')->get() as $row) {
            $queues[$row->queue] = ['total' => (int) $row->total, 'reserved' => (int) $row->reserved, 'ready' => (int) $row->ready];
        }

        $samples[] = [
            't' => round($now - $startedAt, 1),
            'phase' => $running ? 'scenario' : 'drain',
            'processes' => $cpu,
            'queues' => $queues,
            'new_messages' => (int) DB::table('messages')->where('id', '>', $baseline['messages'])->count(),
            'webhook_events_pending' => (int) DB::table('webhook_events')->where('id', '>', $baseline['webhook_events'])->where('status', 'received')->count(),
            'load_average' => ($load = sys_getloadavg()) === false ? null : $load[0],
        ];

        if ($running) {
            continue;
        }

        $nodeSeconds ??= round($now - $startedAt, 1);
        $drainStartedAt ??= $now;
        $ready = array_sum(array_map(fn (array $queue): int => $queue['ready'], $queues));
        $reserved = array_sum(array_map(fn (array $queue): int => $queue['reserved'], $queues));

        if ($ready === 0 && $reserved === 0 && end($samples)['webhook_events_pending'] === 0) {
            $drained = true;
            break;
        }

        if ($now - $drainStartedAt > $drainTimeout) {
            break;
        }
    }

    $nodeStatus = proc_close($node);
    $statusAfter = $globalStatus();
    $result = is_file($resultPath) ? json_decode((string) file_get_contents($resultPath), true) : null;

    $log = is_file($logPath) ? (string) file_get_contents($logPath, offset: $logOffset) : '';
    preg_match_all('/^\[[^\]]+\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY|WARNING): (.{0,160})/m', $log, $matches, PREG_SET_ORDER);
    $logSummary = [];

    foreach ($matches as $match) {
        $key = $match[1].': '.preg_replace('/\d+/', 'N', $match[2]);
        $logSummary[$key] = ($logSummary[$key] ?? 0) + 1;
    }

    arsort($logSummary);

    $statusDelta = [];

    foreach ($statusAfter as $name => $value) {
        $statusDelta[$name] = in_array($name, ['Innodb_row_lock_time_max', 'Innodb_row_lock_current_waits', 'Threads_connected', 'Max_used_connections'], true) ? $value : $value - ($statusBefore[$name] ?? 0);
    }

    $say("Scenario {$label}: node exit {$nodeStatus} after {$nodeSeconds}s; queues ".($drained ? 'drained' : 'NOT drained').' in '.round(microtime(true) - (float) $drainStartedAt, 1).'s');

    return [
        'label' => $label,
        'script' => $script,
        'node_exit' => $nodeStatus,
        'node_seconds' => $nodeSeconds,
        'drained' => $drained,
        'drain_seconds' => round(microtime(true) - (float) $drainStartedAt, 1),
        'baseline' => $baseline,
        'result' => $result,
        'mariadb_status_delta' => $statusDelta,
        'log' => [
            'deadlock_mentions' => preg_match_all('/Deadlock found|SQLSTATE\[40001\]|1213/', $log),
            'lock_wait_timeouts' => preg_match_all('/Lock wait timeout|1205/', $log),
            'levels' => array_slice($logSummary, 0, 15, true),
        ],
        'samples' => $samples,
    ];
};

/* ---------------------------------------------------------- verification */

$summarizeSeconds = function (array $values): array {
    sort($values);
    $count = count($values);
    $at = fn (float $p) => $count === 0 ? null : $values[min($count - 1, max(0, (int) ceil($p / 100 * $count) - 1))];

    return ['count' => $count, 'p50' => $at(50), 'p95' => $at(95), 'p99' => $at(99), 'max' => $count === 0 ? null : $values[$count - 1]];
};

$verify = function (array $run) use ($summarizeSeconds): array {
    $baseline = $run['baseline'];
    $expectations = $run['result']['expectations'] ?? [];
    $checks = [];

    if (isset($expectations['inbound_wamids'])) {
        $expected = array_values(array_unique($expectations['inbound_wamids']));
        $found = [];

        foreach (array_chunk($expected, 1000) as $chunk) {
            foreach (DB::table('messages')->whereIn('whatsapp_message_id', $chunk)->selectRaw('whatsapp_message_id, COUNT(*) AS total')->groupBy('whatsapp_message_id')->get() as $row) {
                $found[$row->whatsapp_message_id] = (int) $row->total;
            }
        }

        $checks['inbound'] = [
            'expected_unique_messages' => count($expected),
            'stored' => count($found),
            'lost' => count($expected) - count($found),
            'duplicated' => count(array_filter($found, fn (int $total): bool => $total > 1)),
            'lost_sample' => array_slice(array_values(array_diff($expected, array_keys($found))), 0, 5),
        ];
    }

    if (! empty($expectations['statuses'])) {
        $expectedStatus = [];

        foreach ($expectations['statuses'] as $expectation) {
            $expectedStatus[(int) $expectation['message_id']] = (string) $expectation['expected'];
        }

        $actual = DB::table('messages')->whereIn('id', array_keys($expectedStatus))->pluck('status', 'id')->all();
        $wrong = array_filter($expectedStatus, fn (string $status, int $id): bool => ($actual[$id] ?? null) !== $status, ARRAY_FILTER_USE_BOTH);
        $checks['statuses'] = [
            'messages' => count($expectedStatus),
            'final_status_as_expected' => count($expectedStatus) - count($wrong),
            'unexpected' => count($wrong),
            'unexpected_sample' => array_map(fn (int $id): array => ['message_id' => $id, 'status' => $actual[$id] ?? null], array_slice(array_keys($wrong), 0, 5)),
        ];
    }

    if (! empty($expectations['contention'])) {
        $rounds = [];

        foreach ($expectations['contention'] as $round) {
            $conversation = DB::table('conversations')->where('id', $round['conversation'])->first(['assigned_user_id', 'status']);
            $acceptEvents = DB::table('conversation_events')->where('conversation_id', $round['conversation'])->where('id', '>', $baseline['conversation_events'])->whereIn('type', ['accepted', 'assigned', 'auto_assigned'])->count();
            $rounds[] = [
                'conversation' => $round['conversation'],
                'http_winners' => count($round['winners']),
                'assigned_user_id' => $conversation?->assigned_user_id,
                'assignee_is_http_winner' => count($round['winners']) === 1 && (int) $conversation?->assigned_user_id === (int) $round['winners'][0],
                'accept_events' => $acceptEvents,
            ];
        }

        $checks['contention'] = [
            'rounds' => count($rounds),
            'exactly_one_winner' => count(array_filter($rounds, fn (array $round): bool => $round['http_winners'] === 1 && $round['accept_events'] === 1 && $round['assignee_is_http_winner'])),
            'detail' => $rounds,
        ];
    }

    if (! empty($expectations['client_references'])) {
        $statuses = [];

        foreach (array_chunk($expectations['client_references'], 1000) as $chunk) {
            foreach (DB::table('messages')->whereIn('client_reference', $chunk)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->get() as $row) {
                $statuses[$row->status] = ($statuses[$row->status] ?? 0) + (int) $row->total;
            }
        }

        $checks['replies'] = [
            'accepted_by_api' => count($expectations['client_references']),
            'stored' => array_sum($statuses),
            'by_status' => $statuses,
            'error_codes' => DB::table('messages')->where('id', '>', $baseline['messages'])->where('direction', 'outbound')->whereNotNull('error_code')->selectRaw('error_code, COUNT(*) AS total')->groupBy('error_code')->pluck('total', 'error_code')->all(),
        ];
    }

    $events = DB::table('webhook_events')->where('id', '>', $baseline['webhook_events']);
    $processing = (clone $events)->whereNotNull('processed_at')->selectRaw('TIMESTAMPDIFF(SECOND, received_at, processed_at) AS seconds')->pluck('seconds')->map(fn ($value): int => (int) $value)->all();
    $checks['webhook_events'] = [
        'stored' => (clone $events)->count(),
        'by_status' => (clone $events)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')->all(),
        'with_retries' => (clone $events)->where('attempts', '>', 1)->count(),
        'errors' => (clone $events)->whereNotNull('error')->selectRaw('error, COUNT(*) AS total')->groupBy('error')->limit(5)->pluck('total', 'error')->all(),
        'processing_seconds' => $summarizeSeconds($processing),
        'duplicate_bodies' => DB::table('webhook_events')->selectRaw('COUNT(*) - COUNT(DISTINCT connection_id, payload_hash) AS duplicates')->value('duplicates'),
    ];

    $checks['integrity'] = [
        'open_conversations_per_contact_gt_1' => DB::query()->fromSub(DB::table('conversations')->whereNotNull('open_lock')->selectRaw('connection_id, contact_id')->groupBy('connection_id', 'contact_id')->havingRaw('COUNT(*) > 1'), 'duplicates')->count(),
        'new_contacts' => DB::table('contacts')->where('id', '>', $baseline['contacts'])->count(),
        'new_contacts_same_phone_gt_1' => DB::query()->fromSub(DB::table('contacts')->where('id', '>', $baseline['contacts'])->whereNotNull('phone_e164')->selectRaw('phone_e164')->groupBy('phone_e164')->havingRaw('COUNT(*) > 1'), 'duplicates')->count(),
        'new_conversations' => DB::table('conversations')->where('id', '>', $baseline['conversations'])->count(),
        'notifications_created' => DB::table('notifications')->where('created_at', '>=', $baseline['started_at'])->count(),
        'conversation_events_created' => DB::table('conversation_events')->where('id', '>', $baseline['conversation_events'])->count(),
        'duplicate_provider_message_ids' => DB::query()->fromSub(DB::table('messages')->whereNotNull('whatsapp_message_id')->selectRaw('whatsapp_message_id')->groupBy('whatsapp_message_id')->havingRaw('COUNT(*) > 1'), 'duplicates')->count(),
    ];

    $failed = DB::table('failed_jobs')->where('id', '>', $baseline['failed_jobs'])->get(['queue', 'exception']);
    $checks['failed_jobs'] = [
        'count' => $failed->count(),
        'by_cause' => $failed->countBy(fn (object $job): string => $job->queue.': '.mb_substr(strtok((string) $job->exception, "\n") ?: '', 0, 160))->all(),
    ];

    $transfers = DB::table('data_transfers')->where('id', '>', $baseline['data_transfers'])->get(['report', 'format', 'status', 'rows_total', 'rows_processed', 'started_at', 'finished_at', 'created_at', 'error']);

    if ($transfers->isNotEmpty()) {
        $checks['exports'] = $transfers->map(fn (object $transfer): array => [
            'report' => $transfer->report,
            'format' => $transfer->format,
            'status' => $transfer->status,
            'rows' => $transfer->rows_total ?? $transfer->rows_processed,
            'queued_seconds' => $transfer->started_at === null ? null : strtotime($transfer->started_at) - strtotime($transfer->created_at),
            'generation_seconds' => $transfer->finished_at === null || $transfer->started_at === null ? null : strtotime($transfer->finished_at) - strtotime($transfer->started_at),
            'error' => $transfer->error === null ? null : mb_substr((string) $transfer->error, 0, 160),
        ])->all();
    }

    return $checks;
};

/**
 * Peak/average per process role and per queue, from the samples.
 */
$aggregate = function (array $samples): array {
    $roles = [];
    $queues = [];

    foreach ($samples as $sample) {
        foreach ($sample['processes'] as $role => $values) {
            $roles[$role]['cpu'][] = $values['cpu_pct'];
            $roles[$role]['rss'][] = $values['rss_mb'];
        }

        foreach ($sample['queues'] as $queue => $values) {
            $queues[$queue][] = $values['ready'] + $values['reserved'];
        }
    }

    $out = ['processes' => [], 'queue_backlog_peak' => [], 'load_average_peak' => max(array_column($samples, 'load_average') ?: [0])];

    foreach ($roles as $role => $values) {
        $out['processes'][$role] = [
            'cpu_pct_avg' => round(array_sum($values['cpu']) / max(1, count($values['cpu'])), 1),
            'cpu_pct_peak' => max($values['cpu']),
            'rss_mb_peak' => max($values['rss']),
        ];
    }

    foreach ($queues as $queue => $values) {
        $out['queue_backlog_peak'][$queue] = max($values);
    }

    return $out;
};

/* -------------------------------------------------------------- profiler */

/**
 * In-process profile of the main pages and endpoints: duration, number of
 * queries, SQL time, slowest queries with their EXPLAIN. Read-only GETs.
 *
 * @return array<string, mixed>
 */
$profile = function () use ($app, $manifest): array {
    $kernel = $app->make(HttpKernel::class);
    /** @var ArrayObject<int, array{sql: string, bindings: array<mixed>, ms: float}> $log */
    $log = new ArrayObject;
    DB::listen(function (QueryExecuted $query) use ($log): void {
        $log->append(['sql' => $query->sql, 'bindings' => $query->bindings, 'ms' => $query->time]);
    });

    $agent = array_values(array_filter($manifest['users']['agents'], fn (array $candidate): bool => count($candidate['open']) > 0))[0];
    $supervisor = array_values($manifest['users']['supervisors'])[0];
    $users = [
        'agente' => User::query()->findOrFail($agent['id']),
        'supervisión' => User::query()->findOrFail($supervisor['id']),
        'superadministración' => User::query()->findOrFail($manifest['users']['superadmin']['id']),
    ];
    $conversation = $agent['open'][0];
    $version = app(HandleInertiaRequests::class)->version(Request::create('/'));

    $targets = [
        ['inbox (mías)', '/conversations?view=mine', null],
        ['inbox (todas)', '/conversations?view=all', null],
        ['inbox (cola)', '/conversations?view=queue', null],
        ['inbox búsqueda', '/conversations?view=all&search=pedido', null],
        ['abrir conversación', "/conversations?c={$conversation}", ['conversations/Index', 'selectedId,conversation,window,timeline,details,contactUrl,availability']],
        ['timeline JSON', "/conversations/{$conversation}/timeline", 'json'],
        ['dashboard (resumen diferido)', '/dashboard', ['Dashboard', 'overview']],
        ['reportes 30d (analítica diferida)', '/reports?period=30d', ['reports/Index', 'analytics']],
        ['reportes 90d (analítica diferida)', '/reports?period=90d', ['reports/Index', 'analytics']],
        ['exportaciones', '/exports', null],
    ];

    $results = [];

    foreach ($users as $role => $user) {
        foreach ($targets as [$name, $uri, $mode]) {
            if ($role === 'agente' && str_starts_with($uri, '/reports')) {
                continue;
            }

            $runs = [];
            $last = [];

            foreach ([1, 2, 3] as $attempt) {
                $log->exchangeArray([]);
                $request = Request::create($uri, 'GET');
                $request->headers->set('X-Requested-With', 'XMLHttpRequest');

                if ($mode === 'json') {
                    $request->headers->set('Accept', 'application/json');
                } else {
                    $request->headers->set('X-Inertia', 'true');
                    $request->headers->set('X-Inertia-Version', (string) $version);

                    if (is_array($mode)) {
                        $request->headers->set('X-Inertia-Partial-Component', $mode[0]);
                        $request->headers->set('X-Inertia-Partial-Data', $mode[1]);
                    }
                }

                $app['auth']->guard('web')->setUser($user);
                $started = microtime(true);
                $response = $kernel->handle($request);
                $ms = (microtime(true) - $started) * 1000;
                $kernel->terminate($request, $response);
                $queries = $log->getArrayCopy();
                $runs[] = ['ms' => $ms, 'status' => $response->getStatusCode(), 'queries' => count($queries), 'sql_ms' => array_sum(array_column($queries, 'ms')), 'bytes' => strlen((string) $response->getContent())];
                $last = $queries;
                $app['auth']->forgetGuards();
            }

            usort($runs, fn (array $a, array $b): int => $a['ms'] <=> $b['ms']);
            $median = $runs[1];
            usort($last, fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);
            $slowest = [];

            foreach (array_slice($last, 0, 3) as $query) {
                $plan = [];

                if (str_starts_with(strtolower(ltrim($query['sql'])), 'select')) {
                    try {
                        foreach (DB::select('EXPLAIN '.$query['sql'], $query['bindings']) as $row) {
                            $plan[] = ['table' => $row->table, 'type' => $row->type, 'key' => $row->key, 'rows' => $row->rows, 'extra' => $row->Extra];
                        }
                    } catch (Throwable) {
                        $plan = [];
                    }
                }

                $slowest[] = ['ms' => round($query['ms'], 2), 'sql' => mb_substr((string) preg_replace('/\s+/', ' ', $query['sql']), 0, 600), 'explain' => $plan];
            }

            // Same statement shape repeated within one request: N+1 candidates.
            $shapes = [];

            foreach ($last as $query) {
                $shape = mb_substr((string) preg_replace(['/\s+/', '/\b\d+\b/'], [' ', 'N'], $query['sql']), 0, 300);
                $shapes[$shape] ??= ['count' => 0, 'ms' => 0.0];
                $shapes[$shape]['count']++;
                $shapes[$shape]['ms'] += $query['ms'];
            }

            $repeated = array_filter($shapes, fn (array $shape): bool => $shape['count'] >= 5);
            uasort($repeated, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

            $results[] = [
                'user' => $role,
                'page' => $name,
                'status' => $median['status'],
                'ms_median_of_3' => round($median['ms'], 1),
                'queries' => $median['queries'],
                'sql_ms' => round($median['sql_ms'], 1),
                'kb' => round($median['bytes'] / 1024, 1),
                'slowest' => $slowest,
                'repeated' => array_map(fn (string $sql, array $shape): array => ['sql' => $sql, 'count' => $shape['count'], 'ms' => round($shape['ms'], 1)], array_keys($repeated), $repeated),
            ];
        }
    }

    return ['endpoints' => $results];
};

/* ----------------------------------------------------------------- run it */

$reverbKey = $reverbCredentials['REVERB_APP_KEY'];
$common = [
    'LOAD_MANIFEST' => $manifestPath,
    'E2E_BASE_URL' => $baseUrl,
    'LOAD_REVERB_PORT' => (string) $reverbPort,
    'LOAD_REVERB_KEY' => $reverbKey,
];

$summary = [
    'generated_at' => now()->toIso8601String(),
    'machine' => [
        'cpu' => trim((string) shell_exec('sysctl -n machdep.cpu.brand_string')),
        'cores' => (int) trim((string) shell_exec('sysctl -n hw.ncpu')),
        'memory_gb' => round((int) trim((string) shell_exec('sysctl -n hw.memsize')) / 1024 ** 3, 1),
        'os' => php_uname('s').' '.php_uname('r'),
        'php' => PHP_VERSION,
        'mariadb' => DB::selectOne('SELECT VERSION() AS v')->v,
        'innodb_buffer_pool_mb' => (int) DB::selectOne('SELECT @@innodb_buffer_pool_size AS v')->v / 1024 / 1024,
        'node' => trim((string) shell_exec(escapeshellarg($knob('NODE_BINARY', 'node')).' --version')),
    ],
    'stack' => [
        'web' => $serverDescription,
        'workers' => $workerDescription,
        'queue' => 'database (MariaDB, tabla jobs)',
        'cache' => 'database',
        'session' => 'database',
        'broadcast' => 'reverb (1 proceso)',
        'whatsapp' => 'SIMULACIÓN (WHATSAPP_TRANSPORT=simulated, webhooks firmados con secretos desechables)',
        'webhook_rate_limit_per_minute' => (int) config('whatsapp.webhooks.rate_limit_per_minute'),
    ],
    'dataset' => ['timings' => $timings, 'counts' => $manifest['counts']],
    'scenarios' => [],
];

$ordinal = 0;

foreach (array_filter(array_map('trim', explode(',', $scenarioArgument))) as $token) {
    [$name, $parameter] = array_pad(explode(':', $token, 2), 2, null);
    $ordinal++;
    $label = $name.($parameter !== null ? "-{$parameter}" : '');
    $extra = [...$common, 'LOAD_RUN_ID' => (string) $ordinal];

    if ($name === 'profile') {
        $say('Profiling pages in process...');
        $summary['scenarios'][$label] = ['label' => $label, 'profile' => $profile()];
        file_put_contents("{$outDir}/{$label}.json", json_encode($summary['scenarios'][$label], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        continue;
    }

    $script = match ($name) {
        'webhooks' => 'webhooks.mjs',
        'agents' => 'agents.mjs',
        'websocket' => 'websocket.mjs',
        'export' => 'export.mjs',
        default => $fail("Unknown scenario [{$name}]."),
    };

    if ($parameter !== null) {
        $extra[match ($name) {
            'webhooks' => 'LOAD_WEBHOOK_TOTAL',
            'agents' => 'LOAD_AGENTS',
            'websocket' => 'LOAD_WS_CONNECTIONS',
            'export' => 'LOAD_EXPORT_WEBHOOKS_PER_S',
        }] = $parameter;
    }

    $say("Running scenario {$label}...");
    $run = $runScenario($script, $extra, $label);
    $run['checks'] = $verify($run);
    $run['resources'] = $aggregate($run['samples']);
    file_put_contents("{$outDir}/{$label}.json", json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    unset($run['samples']);
    $summary['scenarios'][$label] = $run;
}

file_put_contents("{$outDir}/summary.json", json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$stopAll();

$say("Done. Reports: {$outDir}/summary.json");
exit(0);
