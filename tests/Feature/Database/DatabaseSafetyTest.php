<?php

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Symfony\Component\Finder\Finder;
use Tests\Support\TestDatabase;

test('the suite runs on the MariaDB 11.4 testing database', function () {
    $connection = DB::connection();

    expect($connection->getDriverName())->toBe('mariadb')
        ->and($connection->getDatabaseName())->toBe('chatmanager_testing')
        ->and($connection->getDatabaseName())->toBe(config('database.testing_database'))
        ->and($connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION))->toContain('MariaDB')
        ->and($connection->selectOne('SELECT VERSION() AS version')->version)->toStartWith('11.4.');
});

test('destructive commands are blocked on any database but the testing one', function (string $database, string $command) {
    config(['database.connections.mariadb.database' => $database]);
    app()->getProvider(AppServiceProvider::class)->boot();

    $this->artisan($command, ['--force' => true])
        ->expectsOutputToContain('prohibited')
        ->assertFailed();
})->with(['chatmanager', 'test_cm_a_testing', 'chatmanager_verify'])
    ->with(['db:wipe', 'migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback']);

test('destructive commands are always blocked in production', function () {
    config(['database.allow_destructive_commands' => true]);
    app()->detectEnvironment(fn () => 'production');
    app()->getProvider(AppServiceProvider::class)->boot();

    $this->artisan('db:wipe', ['--force' => true])->assertFailed();
});

test('tests refuse to rebuild any database but the testing one', function (string $database) {
    expect(fn () => TestDatabase::guard($database))->toThrow(RuntimeException::class, "Refusing to rebuild [{$database}]");
})->with(['chatmanager', 'test_cm_e2e_testing', 'chatmanager_testing_test_1', 'chatmanager_verify', '']);

test('parallel testing is refused because it would create one database per process', function () {
    ParallelTesting::resolveTokenUsing(fn () => '1');

    try {
        expect(fn () => TestDatabase::guard('chatmanager_testing'))->toThrow(RuntimeException::class, 'Parallel testing is disabled');
    } finally {
        ParallelTesting::resolveTokenUsing(null);
    }
});

test('while a run holds the testing database no other run can take it', function () {
    expect(TestDatabase::tryLock(config('database.connections.mariadb')))->toBeNull();
});

test('application code never refers to a testing database', function () {
    $files = Finder::create()->files()->name('*.php')->in([base_path('app'), base_path('routes'), base_path('bootstrap'), base_path('database')]);

    foreach ($files as $file) {
        expect($file->getContents())->not->toMatch('/\w+_testing\b|_verify\b[\'"]/');
    }
});
