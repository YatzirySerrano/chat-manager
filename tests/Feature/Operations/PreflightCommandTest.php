<?php

use App\Enums\WebhookEventStatus;
use App\Models\Connection;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Support\ManualValidation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

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

test('an unreachable redis server blocks production and only warns elsewhere', function () {
    config([
        'queue.default' => 'redis',
        'database.redis.default' => ['host' => '127.0.0.1', 'port' => 1, 'password' => null, 'database' => 0, 'timeout' => 1],
    ]);

    expect(preflightCheck(preflightJson(), 'redis')['status'])->toBe('warning');

    config(['app.env' => 'production']);

    $check = preflightCheck(preflightJson(), 'redis');

    expect($check['status'])->toBe('fail')
        ->and($check['summary'])->toContain('Redis no responde en la conexión default');
});

test('the redis check does not apply when no driver uses redis', function () {
    expect(preflightCheck(preflightJson(), 'redis')['status'])->toBe('skipped');
});

test('reverb scaling is pinged with its own server settings', function () {
    config([
        'reverb.servers.reverb.scaling.enabled' => true,
        'reverb.servers.reverb.scaling.server' => ['host' => '127.0.0.1', 'port' => 1, 'password' => null, 'database' => 0],
    ]);

    $check = preflightCheck(preflightJson(), 'redis');

    expect($check['status'])->toBe('warning')
        ->and($check['summary'])->toContain('escalado de Reverb')
        ->and($check['summary'])->not->toContain('conexión default');
});

test('a webhook backlog is reported as a warning even when it is critical for the health probe', function () {
    config(['operations.webhooks.critical_backlog' => 1, 'app.env' => 'production']);
    WebhookEvent::query()->create([
        'connection_id' => Connection::factory()->create()->id,
        'payload_hash' => hash('sha256', 'pending'),
        'payload' => '{}',
        'status' => WebhookEventStatus::Received,
        'attempts' => 0,
        'received_at' => now()->subMinutes(30),
    ]);

    $check = preflightCheck(preflightJson(), 'webhooks');

    expect($check['status'])->toBe('warning')
        ->and($check['summary'])->toContain('1 avisos de Meta');
});

test('demo accounts and the simulated connection block production and only warn elsewhere', function () {
    User::factory()->create(['email' => 'agente@'.ManualValidation::EMAIL_DOMAIN]);
    Connection::factory()->create(['phone_number_id' => ManualValidation::PHONE_NUMBER_ID]);

    $local = preflightCheck(preflightJson(), 'demo');
    config(['app.env' => 'production']);
    $production = preflightCheck(preflightJson(), 'demo');

    expect($local['status'])->toBe('warning')
        ->and($production['status'])->toBe('fail')
        ->and($production['summary'])->toContain('1 usuarios activos')
        ->and($production['summary'])->toContain('1 conexiones simuladas');
});

test('the simulated support line is reported as demo data too', function () {
    Connection::factory()->create(['phone_number_id' => ManualValidation::SUPPORT_PHONE_NUMBER_ID]);

    config(['app.env' => 'production']);
    $check = preflightCheck(preflightJson(), 'demo');

    expect($check['status'])->toBe('fail')
        ->and($check['summary'])->toContain('1 conexiones simuladas')
        ->and($check['summary'])->toContain(ManualValidation::SUPPORT_PHONE_NUMBER_ID);
});

test('inactive demo accounts and deleted demo connections are not reported', function () {
    User::factory()->inactive()->create(['email' => 'agente@'.ManualValidation::EMAIL_DOMAIN]);
    Connection::factory()->create(['phone_number_id' => ManualValidation::PHONE_NUMBER_ID, 'is_active' => false, 'deleted_at' => now()]);

    expect(preflightCheck(preflightJson(), 'demo')['status'])->toBe('ok');
});

test('the real transport without verified credentials is not presented as integrated', function () {
    config(['whatsapp.transport' => 'meta']);
    Connection::factory()->create();

    $unverified = preflightCheck(preflightJson(), 'meta');
    Connection::factory()->verified()->create();
    $verified = preflightCheck(preflightJson(), 'meta');

    expect($unverified['status'])->toBe('warning')
        ->and($unverified['summary'])->toContain('no está integrado')
        ->and($verified['status'])->toBe('ok');
});

test('low free disk space is a warning', function () {
    config(['operations.preflight.min_free_disk_mb' => PHP_INT_MAX >> 20]);

    $check = preflightCheck(preflightJson(), 'storage.disk');

    expect($check['status'])->toBe('warning')
        ->and($check['summary'])->toContain('OPS_PREFLIGHT_MIN_FREE_DISK_MB');
});

test('an old newest backup is a warning and a recent one is correct', function () {
    $this->freezeTime();
    $directory = storage_path('framework/testing/preflight-backups');
    File::ensureDirectoryExists($directory);
    File::cleanDirectory($directory);
    touch($directory.'/chatmanager-old.sql.gz', now()->subHours(30)->getTimestamp());
    config(['operations.preflight.backup_directory' => $directory, 'operations.preflight.backup_max_age_hours' => 26]);

    $old = preflightCheck(preflightJson(), 'storage.backups');
    touch($directory.'/chatmanager-new.sql.gz', now()->subHours(2)->getTimestamp());
    $recent = preflightCheck(preflightJson(), 'storage.backups');
    File::deleteDirectory($directory);

    expect($old['status'])->toBe('warning')
        ->and($old['summary'])->toContain('30 h')
        ->and($recent['status'])->toBe('ok');
});

test('a production preflight without a backup directory warns and a local one skips the check', function () {
    expect(preflightCheck(preflightJson(), 'storage.backups')['status'])->toBe('skipped');

    config(['app.env' => 'production']);

    expect(preflightCheck(preflightJson(), 'storage.backups')['status'])->toBe('warning');
});

test('rate limits kept in the database warn while a public web chat is active', function () {
    config(['cache.default' => 'database', 'cache.limiter' => null]);

    expect(preflightCheck(preflightJson(), 'cache')['status'])->toBe('ok');

    Connection::factory()->webChat()->create();

    $check = preflightCheck(preflightJson(), 'cache');

    expect($check['status'])->toBe('warning')
        ->and($check['summary'])->toContain('CACHE_LIMITER=redis');

    config(['cache.limiter' => 'file']);

    expect(preflightCheck(preflightJson(), 'cache'))
        ->status->toBe('ok')
        ->summary->toContain('límites de uso en file (file)');
});
