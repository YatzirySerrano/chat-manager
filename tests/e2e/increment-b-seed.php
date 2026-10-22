<?php

/*
| Data for the increment B browser scenarios (tests/e2e/increment-b.mjs).
|
| It runs the real application services against the testing database
| (chatmanager_testing), already rebuilt by tests/e2e/run.php, and refuses
| any other database.
| Inbound messages are simulated in the documented WhatsApp Cloud API
| webhook format; nothing is sent to or received from Meta.
|
| Run with APP_ENV=testing so .env.testing is loaded:
|
|   php tests/e2e/increment-b-seed.php setup
|   php tests/e2e/increment-b-seed.php inbound <wa_id> <profile name> <text>
*/

use App\Actions\Conversations\ProcessInboundMessages;
use App\Enums\ConversationStatus;
use App\Enums\DefaultRole;
use App\Enums\DistributionMode;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
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

const E2E_PHONE_NUMBER_ID = '880000000000002';
const E2E_PASSWORD = 'e2e-password';

$command = $argv[1] ?? 'setup';

if ($command === 'inbound') {
    [, , $waId, $name, $text] = $argv + [null, null, '5215511112222', 'Cliente E2E', 'Hola'];
    $connection = Connection::query()->where('phone_number_id', E2E_PHONE_NUMBER_ID)->firstOrFail();

    $receipts = app(ProcessInboundMessages::class)->handle([
        'object' => 'whatsapp_business_account',
        'entry' => [['id' => 'E2E', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['display_phone_number' => '5215500000002', 'phone_number_id' => E2E_PHONE_NUMBER_ID],
            'contacts' => [['profile' => ['name' => $name], 'wa_id' => $waId]],
            'messages' => [[
                'from' => $waId,
                'id' => 'wamid.e2e.'.Str::random(12),
                'timestamp' => (string) now()->getTimestamp(),
                'type' => 'text',
                'text' => ['body' => $text],
            ]],
        ]]]]],
    ]);

    echo json_encode(array_map(fn ($receipt) => [
        'conversation' => $receipt->conversation->id,
        'outcome' => $receipt->outcome->value,
        'status' => $receipt->conversation->status->value,
    ], $receipts), JSON_PRETTY_PRINT), PHP_EOL;

    exit(0);
}

$user = fn (string $email, string $name, DefaultRole $role, array $departments = []) => User::query()->where('email', $email)->first()
    ?? User::factory()->withRole($role)->inDepartments($departments)->create([
        'name' => $name,
        'email' => $email,
        'password' => E2E_PASSWORD,
    ]);

$sales = Department::query()->firstOrCreate(['slug' => 'e2e-ventas'], ['name' => 'E2E Ventas', 'color' => 'emerald']);
$support = Department::query()->firstOrCreate(['slug' => 'e2e-soporte'], ['name' => 'E2E Soporte', 'color' => 'blue']);
$sales->forceFill(['distribution_mode' => DistributionMode::Shared, 'is_active' => true])->save();
$support->forceFill(['distribution_mode' => DistributionMode::Shared, 'is_active' => true])->save();

$admin = $user('e2e-admin@example.test', 'E2E Administración', DefaultRole::SuperAdministrator);
$ana = $user('e2e-ana@example.test', 'E2E Ana', DefaultRole::Agent, [$sales]);
$beto = $user('e2e-beto@example.test', 'E2E Beto', DefaultRole::Agent, [$sales]);
$carla = $user('e2e-carla@example.test', 'E2E Carla', DefaultRole::Agent, [$support]);

$connection = Connection::query()->firstOrCreate(['phone_number_id' => E2E_PHONE_NUMBER_ID], [
    'name' => 'E2E WhatsApp Ventas',
    'display_phone_number' => '+52 55 0000 0002',
]);
$connection->forceFill(['default_department_id' => $sales->id, 'reopen_window_minutes' => null])->save();
$connection->departments()->syncWithoutDetaching([$sales->id, $support->id]);

echo json_encode([
    'database' => $database,
    'password' => E2E_PASSWORD,
    'users' => ['admin' => $admin->email, 'ana' => $ana->email, 'beto' => $beto->email, 'carla' => $carla->email],
    'departments' => ['sales' => $sales->id, 'support' => $support->id],
    'connection' => $connection->id,
    'conversations' => Conversation::query()->count(),
    'pending' => Conversation::query()->where('status', ConversationStatus::Pending)->count(),
], JSON_PRETTY_PRINT), PHP_EOL;
