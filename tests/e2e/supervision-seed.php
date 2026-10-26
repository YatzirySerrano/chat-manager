<?php

/*
| Data for the multi-user supervision scenario (tests/e2e/supervision.mjs),
| on top of tests/e2e/increment-b-seed.php (already run by
| tests/e2e/run.php: superadministrator, Ana and Beto in E2E Ventas, Carla in
| E2E Soporte, all with the Agente role, and the E2E WhatsApp connection).
| Adds the non-root Administrador (global), the Ventas supervisor
| (departments scope), a conversation that only ever lived in Soporte and
| one Beto attends (for the live permission change).
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
|   php tests/e2e/supervision-seed.php prepare
|   php tests/e2e/supervision-seed.php message <wa_id> <profile name> <text>
|   php tests/e2e/supervision-seed.php version <conversation id>
*/

use App\Actions\Conversations\AcceptConversation;
use App\Actions\Conversations\ProcessInboundMessages;
use App\Enums\DefaultRole;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
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

const SV_PHONE_NUMBER_ID = '880000000000002';
const SV_BUSINESS_ACCOUNT_ID = '990000000000002';
const SV_PASSWORD = 'e2e-password';

$command = $argv[1] ?? 'prepare';

$webhookMessage = fn (string $waId, string $name, string $text, int $timestamp): array => SimulatedWebhook::message(
    SV_PHONE_NUMBER_ID,
    SV_BUSINESS_ACCOUNT_ID,
    $waId,
    $name,
    'wamid.e2e.'.Str::random(16),
    'text',
    $text,
    $timestamp,
);

if ($command === 'message') {
    [, , $waId, $name, $text] = $argv + [null, null, '5215530000001', 'Cliente E2E', 'Hola'];

    echo json_encode($webhookMessage($waId, $name, $text, now()->getTimestamp()), JSON_UNESCAPED_UNICODE), PHP_EOL;

    exit(0);
}

if ($command === 'version') {
    echo (int) Conversation::query()->whereKey((int) ($argv[2] ?? 0))->value('version'), PHP_EOL;

    exit(0);
}

if ($command !== 'prepare') {
    fwrite(STDERR, "Unknown command [{$command}].".PHP_EOL);
    exit(1);
}

$connection = Connection::query()->where('phone_number_id', SV_PHONE_NUMBER_ID)->firstOrFail();
$appSecret = Str::random(40);

// Without stored credentials the connection cannot send (Connection::
// sendingBlockReason); these are throwaway values for the simulated transport.
$connection->forceFill([
    'is_active' => true,
    'business_account_id' => SV_BUSINESS_ACCOUNT_ID,
    'access_token' => 'E2E-SIMULATED-'.Str::random(32),
    'app_secret' => $appSecret,
])->save();

$sales = Department::query()->where('slug', 'e2e-ventas')->firstOrFail();
$support = Department::query()->where('slug', 'e2e-soporte')->firstOrFail();
$beto = User::query()->where('email', 'e2e-beto@example.test')->firstOrFail();
$carla = User::query()->where('email', 'e2e-carla@example.test')->firstOrFail();

$administrator = User::query()->where('email', 'e2e-administrador@example.test')->first()
    ?? User::factory()->withRole(DefaultRole::Administrator)->create([
        'name' => 'E2E Administrador',
        'email' => 'e2e-administrador@example.test',
        'password' => SV_PASSWORD,
    ]);

$supervisor = User::query()->where('email', 'e2e-supervisor@example.test')->first()
    ?? User::factory()->withRole(DefaultRole::Supervisor)->inDepartments([$sales])->create([
        'name' => 'E2E Supervisora',
        'email' => 'e2e-supervisor@example.test',
        'password' => SV_PASSWORD,
    ]);

// Beto attends this one: his transfer action follows his role live.
$receipts = app(ProcessInboundMessages::class)->handle(
    $webhookMessage('5215530000009', 'Cliente Permisos E2E', 'Hola, ¿siguen abiertos?', now()->subMinutes(5)->getTimestamp()),
);
$betoConversation = app(AcceptConversation::class)->handle($receipts[0]->conversation->refresh(), $beto->refresh())->refresh();

// Only ever in Soporte: neither Ventas agents nor the Ventas supervisor reach it.
$supportOnly = Conversation::factory()->inDepartment($support)->assignedTo($carla)->create([
    'connection_id' => $connection->id,
    'contact_id' => Contact::factory()->create(['name' => 'Cliente Solo Soporte SV'])->id,
]);

echo json_encode([
    'database' => $database,
    'webhook_key' => $connection->webhook_key,
    'app_secret' => $appSecret,
    'users' => [
        'administrator' => $administrator->email,
        'supervisor' => $supervisor->email,
    ],
    'roles' => [
        'administrator' => $administrator->roles()->pluck('label')->all(),
        'supervisor' => $supervisor->roles()->pluck('label')->all(),
        'beto' => $beto->roles()->pluck('label')->all(),
        'beto_direct_permissions' => $beto->getDirectPermissions()->pluck('name')->all(),
    ],
    'departments' => ['sales' => $sales->id, 'support' => $support->id],
    'conversations' => [
        'beto' => $betoConversation->id,
        'support_only' => $supportOnly->id,
    ],
    'transport' => config('whatsapp.transport'),
], JSON_UNESCAPED_UNICODE), PHP_EOL;
