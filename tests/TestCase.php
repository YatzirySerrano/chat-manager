<?php

namespace Tests;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Features;
use PDO;
use RuntimeException;
use Tests\Support\TestDatabase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Roles and permissions are reference data required by every feature test.
     */
    protected bool $seed = true;

    /**
     * @var class-string
     */
    protected string $seeder = RolesAndPermissionsSeeder::class;

    /**
     * Refuse to rebuild any database other than the MariaDB testing database,
     * so a misconfigured environment can never wipe the application database,
     * and hold it exclusively while the suite runs.
     */
    protected function beforeRefreshingDatabase(): void
    {
        $name = DB::getDefaultConnection();
        TestDatabase::guard((string) config("database.connections.{$name}.database"));

        $connection = DB::connection();
        $serverVersion = (string) $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);

        if ($connection->getDriverName() !== 'mariadb' || ! str_contains($serverVersion, 'MariaDB')) {
            throw new RuntimeException("The test suite must run on MariaDB; connected to [{$connection->getDriverName()} {$serverVersion}].");
        }

        TestDatabase::lock(config("database.connections.{$name}"));
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
