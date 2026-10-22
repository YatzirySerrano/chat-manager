<?php

use Illuminate\Support\Facades\Artisan;

/**
 * @return array{status: string, checks: list<array{key: string, status: string, summary: string}>}
 */
function preflightJson(array $options = []): array
{
    Artisan::call('app:preflight', ['--json' => true, ...$options]);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @param  array{checks: list<array{key: string, status: string, summary: string}>}  $result
 * @return array{key: string, status: string, summary: string}
 */
function preflightCheck(array $result, string $key): array
{
    return collect($result['checks'])->firstWhere('key', $key) ?? throw new RuntimeException("Missing check {$key}");
}

test('the preflight passes on the testing environment and reports every area', function () {
    $exitCode = Artisan::call('app:preflight', ['--json' => true]);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($result['status'])->not->toBe('fail')
        ->and(collect($result['checks'])->pluck('key')->all())->toContain(
            'php.version', 'php.extensions', 'app.debug', 'app.key', 'app.url', 'http.session',
            'database.server', 'database.migrations', 'storage', 'storage.private', 'cache', 'queue',
            'scheduler.commands', 'scheduler.heartbeat', 'realtime', 'exports.pdf', 'meta', 'mail', 'timezone',
        )
        ->and(preflightCheck($result, 'database.server')['status'])->toBe('ok')
        ->and(preflightCheck($result, 'scheduler.commands')['status'])->toBe('ok');
});

test('the preflight fails a production configuration with debug, plain http and synchronous queues', function () {
    config([
        'app.env' => 'production',
        'app.debug' => true,
        'app.url' => 'http://chat.example.com',
        'queue.default' => 'sync',
        'whatsapp.transport' => 'simulated',
    ]);

    $exitCode = Artisan::call('app:preflight', ['--json' => true]);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($result['status'])->toBe('fail')
        ->and(preflightCheck($result, 'app.debug')['status'])->toBe('fail')
        ->and(preflightCheck($result, 'app.url')['status'])->toBe('fail')
        ->and(preflightCheck($result, 'queue')['status'])->toBe('fail')
        ->and(preflightCheck($result, 'meta')['status'])->toBe('fail')
        ->and(preflightCheck($result, 'meta')['summary'])->toContain('simulación');
});

test('the same problems are only warnings outside production', function () {
    config(['app.env' => 'staging', 'app.debug' => true, 'queue.default' => 'sync']);

    $result = preflightJson();

    expect(preflightCheck($result, 'app.debug')['status'])->toBe('warning')
        ->and(preflightCheck($result, 'queue')['status'])->toBe('warning');
});

test('a missing application key, an invalid graph version or a short retry window always fail', function () {
    config([
        'app.key' => '',
        'services.meta.graph_version' => 'latest',
        'queue.default' => 'database',
        'queue.connections.database.retry_after' => 300,
        'exports.job_timeout' => 600,
    ]);

    $result = preflightJson();

    expect(preflightCheck($result, 'app.key')['status'])->toBe('fail')
        ->and(preflightCheck($result, 'meta')['status'])->toBe('fail')
        ->and(preflightCheck($result, 'queue')['summary'])->toContain('retry_after');
});

test('secure cookies are required when the application is served over https in production', function () {
    config(['app.env' => 'production', 'app.url' => 'https://chat.example.com', 'session.driver' => 'database', 'session.secure' => false]);

    expect(preflightCheck(preflightJson(), 'http.session')['status'])->toBe('fail');

    config(['session.secure' => true]);

    expect(preflightCheck(preflightJson(), 'http.session')['status'])->toBe('ok');
});

test('redis drivers require the phpredis extension when it is the configured client', function () {
    config(['cache.default' => 'redis', 'database.redis.client' => 'phpredis']);

    $check = preflightCheck(preflightJson(), 'php.optional');

    expect($check['status'])->toBe(extension_loaded('redis') ? 'ok' : 'fail');
});

test('a public disk for attachments is reported', function () {
    config(['filesystems.media_disk' => 'public']);

    expect(preflightCheck(preflightJson(), 'storage.private')['summary'])->toContain('media (public) es público');
});

test('strict mode turns warnings into a failing exit code', function () {
    config(['app.env' => 'staging']);

    expect(Artisan::call('app:preflight', ['--strict' => true]))->toBe(1);
});

test('the preflight never writes to the database', function () {
    $writes = databaseWritesDuring(fn () => Artisan::call('app:preflight', ['--json' => true]));

    expect($writes)->toBe([]);
});
