<?php

/*
| Data for the roles browser scenario (tests/e2e/roles.mjs), on top of
| tests/e2e/increment-b-seed.php (already run by tests/e2e/run.php:
| superadministrator, and Ana, Beto and Carla with the Agente role).
| Adds the non-root Administrador account, so the scenario can check that
| the root role stays invisible to it.
|
| It only touches the testing database (chatmanager_testing) and refuses any
| other one. Run with APP_ENV=testing:
|
|   php tests/e2e/roles-seed.php
*/

use App\Enums\DefaultRole;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestDatabase;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = DB::connection()->getDatabaseName();

try {
    TestDatabase::guard($database);
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}

$administrator = User::query()->where('email', 'e2e-administrador@example.test')->first()
    ?? User::factory()->withRole(DefaultRole::Administrator)->create([
        'name' => 'E2E Administrador',
        'email' => 'e2e-administrador@example.test',
        'password' => 'e2e-password',
    ]);

echo json_encode(['administrator' => $administrator->email], JSON_PRETTY_PRINT), PHP_EOL;
