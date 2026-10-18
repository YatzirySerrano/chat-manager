<?php

namespace Tests;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Features;
use PDO;
use RuntimeException;

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
     * Refuse to rebuild any database other than a dedicated MariaDB "_testing" one,
     * so a misconfigured environment can never wipe the application database.
     */
    protected function beforeRefreshingDatabase(): void
    {
        $connection = DB::connection();
        $database = $connection->getDatabaseName();
        $serverVersion = (string) $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);

        if ($connection->getDriverName() !== 'mariadb' || ! str_contains($serverVersion, 'MariaDB')) {
            throw new RuntimeException("The test suite must run on MariaDB; connected to [{$connection->getDriverName()} {$serverVersion}].");
        }

        if (! str_ends_with($database, '_testing')) {
            throw new RuntimeException("Refusing to refresh [{$database}]: the test database name must end in \"_testing\".");
        }
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
