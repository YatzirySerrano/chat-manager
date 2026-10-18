<?php

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\DB;

test('the suite runs on a dedicated MariaDB 11.4 testing database', function () {
    $connection = DB::connection();

    expect($connection->getDriverName())->toBe('mariadb')
        ->and($connection->getDatabaseName())->toEndWith('_testing')
        ->and($connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION))->toContain('MariaDB')
        ->and($connection->selectOne('SELECT VERSION() AS version')->version)->toStartWith('11.4.');
});

test('destructive commands are blocked on any database that is not a testing one', function (string $command) {
    config(['database.connections.mariadb.database' => 'chatmanager']);
    app()->getProvider(AppServiceProvider::class)->boot();

    $this->artisan($command, ['--force' => true])
        ->expectsOutputToContain('prohibited')
        ->assertFailed();
})->with(['db:wipe', 'migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback']);

test('destructive commands are always blocked in production', function () {
    config(['database.allow_destructive_commands' => true]);
    app()->detectEnvironment(fn () => 'production');
    app()->getProvider(AppServiceProvider::class)->boot();

    $this->artisan('db:wipe', ['--force' => true])->assertFailed();
});
