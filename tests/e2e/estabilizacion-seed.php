<?php

/*
| Data for the multi-session stabilization scenario
| (tests/e2e/estabilizacion.mjs), on top of tests/e2e/increment-b-seed.php
| (already run by tests/e2e/run.php: superadministrator, Ana and Beto in
| E2E Ventas, Carla in E2E Soporte and the E2E WhatsApp connection).
|
| It only touches the testing database (chatmanager_testing) and refuses any
| other one.
|
| SIMULACIÓN: nothing is sent to or received from Meta. The connection gets
| throwaway credentials and an app secret that only exist in
| chatmanager_testing for this run; the scenario signs the webhook bodies
| printed by "message" with that secret and posts them to the local server.
| Replies go through WHATSAPP_TRANSPORT=simulated.
|
| Run with APP_ENV=testing so .env.testing is loaded:
|
|   php tests/e2e/estabilizacion-seed.php prepare
|   php tests/e2e/estabilizacion-seed.php message <wa_id> <profile name> <text>
*/

use App\Actions\Conversations\ChangeAgentAvailability;
use App\Enums\AgentAvailability;
use App\Enums\DefaultRole;
use App\Models\Connection;
use App\Models\Department;
use App\Models\User;
use App\Services\Meta\SimulatedWebhook;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

const STAB_PHONE_NUMBER_ID = '880000000000002';
const STAB_BUSINESS_ACCOUNT_ID = '990000000000002';
const STAB_PASSWORD = 'e2e-password';
const STAB_REOPEN_WINDOW_MINUTES = 60;

$command = $argv[1] ?? 'prepare';

if ($command === 'message') {
    [, , $waId, $name, $text] = $argv + [null, null, '5215511118888', 'Cliente E2E', 'Hola'];

    echo json_encode(SimulatedWebhook::message(
        STAB_PHONE_NUMBER_ID,
        STAB_BUSINESS_ACCOUNT_ID,
        $waId,
        $name,
        'wamid.e2e.'.Str::random(16),
        'text',
        $text,
        now()->getTimestamp(),
    ), JSON_UNESCAPED_UNICODE), PHP_EOL;

    exit(0);
}

if ($command !== 'prepare') {
    fwrite(STDERR, "Unknown command [{$command}].".PHP_EOL);
    exit(1);
}

$connection = Connection::query()->where('phone_number_id', STAB_PHONE_NUMBER_ID)->firstOrFail();
$appSecret = Str::random(40);

// Without stored credentials the connection cannot send (Connection::
// sendingBlockReason); these are throwaway values for the simulated transport.
$connection->forceFill([
    'is_active' => true,
    'business_account_id' => STAB_BUSINESS_ACCOUNT_ID,
    'access_token' => 'E2E-SIMULATED-'.Str::random(32),
    'app_secret' => $appSecret,
    'reopen_window_minutes' => STAB_REOPEN_WINDOW_MINUTES,
])->save();

$administrator = User::query()->where('email', 'e2e-administrador@example.test')->first()
    ?? User::factory()->withRole(DefaultRole::Administrator)->create([
        'name' => 'E2E Administrador',
        'email' => 'e2e-administrador@example.test',
        'password' => STAB_PASSWORD,
    ]);

$agents = User::query()
    ->with('roles')
    ->whereIn('email', ['e2e-ana@example.test', 'e2e-beto@example.test', 'e2e-carla@example.test'])
    ->get()
    ->keyBy('email');

foreach ($agents as $agent) {
    app(ChangeAgentAvailability::class)->handle($agent, AgentAvailability::Available);
}

echo json_encode([
    'database' => $database,
    'connection' => $connection->id,
    'webhook_key' => $connection->webhook_key,
    'app_secret' => $appSecret,
    'reopen_window_minutes' => STAB_REOPEN_WINDOW_MINUTES,
    'administrator' => $administrator->email,
    'agents' => $agents->map(fn (User $agent): int => $agent->id)->all(),
    'departments' => [
        'sales' => Department::query()->where('slug', 'e2e-ventas')->value('id'),
        'support' => Department::query()->where('slug', 'e2e-soporte')->value('id'),
    ],
    'transport' => config('whatsapp.transport'),
]), PHP_EOL;
