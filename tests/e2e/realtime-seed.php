<?php

/*
| Extra data for the real-time browser scenario (tests/e2e/realtime.mjs),
| on top of tests/e2e/increment-b-seed.php (already run by tests/e2e/run.php).
|
| It only touches the testing database (chatmanager_testing) and refuses any
| other one. The E2E WhatsApp connection is activated so outbound replies
| go through the simulated transport (WHATSAPP_TRANSPORT=simulated, SIMULACIÓN:
| nothing is sent to Meta).
|
|   APP_ENV=testing php tests/e2e/realtime-seed.php prepare
*/

use App\Models\Connection;
use App\Models\Department;
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

$connection = Connection::query()->where('phone_number_id', '880000000000002')->firstOrFail();
$connection->forceFill(['is_active' => true])->save();

echo json_encode([
    'connection' => $connection->id,
    'departments' => [
        'sales' => Department::query()->where('slug', 'e2e-ventas')->value('id'),
        'support' => Department::query()->where('slug', 'e2e-soporte')->value('id'),
    ],
    'transport' => config('whatsapp.transport'),
]), PHP_EOL;
