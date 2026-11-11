<?php

/*
| Data of the connections monitoring scenario
| (tests/e2e/conexiones-monitoreo.mjs), on top of
| tests/e2e/increment-b-seed.php (already run by tests/e2e/run.php: the
| superadministrator, E2E Ana, E2E Ventas and the "E2E WhatsApp Ventas"
| connection, which has no evidence at all).
|
| Four WhatsApp connections created with the model factory, each with the
| RECORDED evidence of one situation (nothing is checked against Meta):
|
|   comprobada   Meta accepted the credentials 5 minutes ago (recent check)
|   historica    verified 3 days ago and nothing since (history, not "now")
|   avisos       working, but Meta answered that the app is not subscribed
|                and reports the number as FLAGGED (two warnings)
|   rechazada    Meta rejected the credentials in the last check (problem)
|
|   php tests/e2e/conexiones-monitoreo-seed.php
|       -> {"comprobada", "historica", "avisos", "rechazada", "sin_evidencia",
|           "monitoring": {name: ConnectionMonitoring::for()}}
|
| It only touches the testing database (chatmanager_testing) and refuses any
| other one. SIMULACIÓN: nothing is sent to or received from Meta.
*/

use App\Models\Connection;
use App\Models\Department;
use App\Services\Meta\ConnectionMonitoring;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestDatabase;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    TestDatabase::guard(DB::connection()->getDatabaseName());
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}

$sales = Department::query()->where('slug', 'e2e-ventas')->firstOrFail();
$base = ['default_department_id' => $sales->id, 'is_active' => true];

$connections = [
    'comprobada' => Connection::factory()->operational()->create([
        ...$base,
        'name' => 'E2E Conexión comprobada',
        'credentials_verified_at' => now()->subMinutes(5),
        'last_inbound_at' => now()->subDays(3),
        'last_outbound_at' => now()->subDays(3),
        'last_activity_at' => now()->subDays(3),
        'app_subscribed' => true,
        'subscription_checked_at' => now()->subMinutes(5),
    ]),
    'historica' => Connection::factory()->verified()->create([
        ...$base,
        'name' => 'E2E Conexión histórica',
        'credentials_verified_at' => now()->subDays(3),
        'webhook_verified_at' => now()->subDays(10),
    ]),
    'avisos' => Connection::factory()->operational()->create([
        ...$base,
        'name' => 'E2E Conexión con avisos',
        'credentials_verified_at' => now()->subMinutes(10),
        'phone_status' => 'FLAGGED',
        'app_subscribed' => false,
        'subscription_checked_at' => now()->subMinutes(10),
    ]),
    'rechazada' => Connection::factory()->withInvalidCredentials()->create([
        ...$base,
        'name' => 'E2E Conexión rechazada',
    ]),
];

$untouched = Connection::query()->where('phone_number_id', '880000000000002')->firstOrFail();

echo json_encode([
    ...array_map(fn (Connection $connection): int => $connection->id, $connections),
    'sin_evidencia' => $untouched->id,
    'monitoring' => [
        ...array_map(fn (Connection $connection): array => ConnectionMonitoring::for($connection->refresh()), $connections),
        'sin_evidencia' => ConnectionMonitoring::for($untouched),
    ],
]), PHP_EOL;
