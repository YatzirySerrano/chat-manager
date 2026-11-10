<?php

/*
| Data of the attention scenario (tests/e2e/atencion.mjs), on top of
| tests/e2e/increment-b-seed.php (already run by tests/e2e/run.php: E2E Ana
| and E2E Beto, agents of E2E Ventas, and the "E2E WhatsApp Ventas"
| connection).
|
| It adds, with the control of pending conversations on (10 minutes):
|   - waiting: a conversation attended by E2E Ana whose customer wrote two
|     messages 30 minutes ago and got no answer (Ana is blocked);
|   - queued: a pending conversation of E2E Ventas without agent;
|   - thread: a private internal chat Beto → Ana with two unread messages.
|
| It only touches the testing database (chatmanager_testing) and refuses any
| other one. SIMULACIÓN: nothing is sent to or received from Meta.
|
| It also gives E2E Ana her own pink colors for the waiting conversation
| (the preview must show them).
|
|   php tests/e2e/atencion-seed.php prepare
|       -> {"waiting", "queued", "thread", "ana", "waiting_contact", "colors"}
|   php tests/e2e/atencion-seed.php dashboard off|on
|       -> the Agent role loses or gets back «Ver el panel principal»
*/

use App\Actions\Conversations\ProcessInboundMessages;
use App\Enums\ConversationStatus;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\ChatAppearance;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Role;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Services\Meta\SimulatedWebhook;
use App\Services\PlatformSettings;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
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

const ATTENTION_PHONE_NUMBER_ID = '880000000000002';

if (($argv[1] ?? 'prepare') === 'dashboard') {
    $role = Role::query()->where('name', DefaultRole::Agent->value)->firstOrFail();
    ($argv[2] ?? '') === 'off'
        ? $role->revokePermissionTo(Permission::ViewDashboard->value)
        : $role->givePermissionTo(Permission::ViewDashboard->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    echo json_encode(['dashboard' => $role->fresh()?->hasPermissionTo(Permission::ViewDashboard->value)]), PHP_EOL;
    exit(0);
}

if (($argv[1] ?? 'prepare') !== 'prepare') {
    fwrite(STDERR, 'Unknown command.'.PHP_EOL);
    exit(1);
}

app(PlatformSettings::class)->update([
    'operations' => ['pending_block_enabled' => true, 'pending_block_after_minutes' => 10],
    'business_hours' => ['enabled' => false],
]);

$ana = User::query()->where('email', 'e2e-ana@example.test')->firstOrFail();
$beto = User::query()->where('email', 'e2e-beto@example.test')->firstOrFail();
$sales = Department::query()->where('slug', 'e2e-ventas')->firstOrFail();
$whatsapp = Connection::query()->where('phone_number_id', ATTENTION_PHONE_NUMBER_ID)->firstOrFail();
$whatsapp->forceFill([
    'is_active' => true,
    'access_token' => $whatsapp->access_token ?? 'E2E-SIMULATED-'.Str::random(32),
    'app_secret' => $whatsapp->app_secret ?? Str::random(40),
    'welcome_enabled' => false,
    'default_department_id' => $sales->id,
])->save();

$inbound = function (string $waId, string $name, string $text, int $minutesAgo) use ($whatsapp): Conversation {
    $receipts = app(ProcessInboundMessages::class)->handle(SimulatedWebhook::message(
        ATTENTION_PHONE_NUMBER_ID,
        (string) $whatsapp->business_account_id,
        $waId,
        $name,
        'wamid.e2e.'.Str::random(16),
        'text',
        $text,
        now()->subMinutes($minutesAgo)->getTimestamp(),
    ));

    return $receipts[0]->conversation->refresh();
};

$waiting = $inbound('5215511170001', 'Cliente Esperando', 'Hola, ¿me ayudan con mi pedido?', 31);
$inbound('5215511170001', 'Cliente Esperando', 'Sigo esperando, por favor', 30);
$waiting->refresh()->forceFill([
    'status' => ConversationStatus::Open,
    'department_id' => $sales->id,
    'assigned_user_id' => $ana->id,
    'assigned_at' => now()->subMinutes(31),
    'last_inbound_at' => now()->subMinutes(30),
    'last_outbound_at' => null,
])->save();

$colors = [
    'light' => ['sent_bg' => '#FBCFE8', 'sent_text' => '#500724', 'received_bg' => '#FCE7F3', 'received_text' => '#500724', 'chat_bg' => '#FDF2F8'],
    'dark' => ['sent_bg' => '#9D174D', 'sent_text' => '#FFFFFF', 'received_bg' => '#500724', 'received_text' => '#FCE7F3', 'chat_bg' => '#2A0A18'],
];
ChatAppearance::query()->where('user_id', $ana->id)->delete();
ChatAppearance::factory()->forConversation($waiting)->create(['user_id' => $ana->id, 'preset' => null, 'colors' => $colors]);

$queued = $inbound('5215511170002', 'Cliente Nuevo', 'Buenas tardes, quiero información', 1);
$queued->refresh()->forceFill([
    'status' => ConversationStatus::Pending,
    'department_id' => $sales->id,
    'assigned_user_id' => null,
])->save();

$thread = TeamThread::factory()->direct($ana, $beto)->create();
TeamMessage::factory()->in($thread, $beto)->create(['body' => 'Ana, ¿puedes revisar el pedido 4521?']);
TeamMessage::factory()->in($thread, $beto)->create(['body' => 'Es del cliente de esta mañana.']);

echo json_encode([
    'waiting' => $waiting->id,
    'queued' => $queued->id,
    'thread' => $thread->id,
    'ana' => $ana->id,
    'waiting_contact' => 'Cliente Esperando',
    'colors' => $colors['light'],
]), PHP_EOL;
