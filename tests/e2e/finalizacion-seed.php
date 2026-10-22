<?php

/*
| Data for the finalization browser scenarios (tests/e2e/finalizacion.mjs).
|
| It complements tests/e2e/increment-b-seed.php (already run by
| tests/e2e/run.php) and only works on the testing database
| (chatmanager_testing): any other database is refused.
|
| SIMULACIÓN: nothing is sent to or received from Meta. Webhook bodies are
| built in the documented WhatsApp Cloud API format; the scenario signs them
| with the throwaway app secret generated here and posts them to the local
| server. The app secret only exists in chatmanager_testing for this run.
|
| Run with APP_ENV=testing so .env.testing is loaded:
|
|   php tests/e2e/finalizacion-seed.php setup
|   php tests/e2e/finalizacion-seed.php message <wa_id> <profile name> <text>
|   php tests/e2e/finalizacion-seed.php status <message id> <sent|delivered|read>
*/

use App\Actions\Conversations\AcceptConversation;
use App\Actions\Conversations\ChangeAgentAvailability;
use App\Actions\Conversations\ProcessInboundMessages;
use App\Actions\Conversations\QueueOutboundMessage;
use App\Actions\Conversations\TransferConversation;
use App\Enums\AgentAvailability;
use App\Enums\DefaultRole;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\QuickReply;
use App\Models\User;
use App\Services\Meta\SimulatedWebhook;
use App\Services\Meta\WhatsAppTransport;
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

const FINAL_PHONE_NUMBER_ID = '880000000000002';
const FINAL_BUSINESS_ACCOUNT_ID = '990000000000002';
const FINAL_PASSWORD = 'e2e-password';

$command = $argv[1] ?? 'setup';
$connection = Connection::query()->where('phone_number_id', FINAL_PHONE_NUMBER_ID)->firstOrFail();

if ($command === 'message') {
    [, , $waId, $name, $text] = $argv + [null, null, '5215511113333', 'Cliente E2E', 'Hola'];

    echo json_encode(SimulatedWebhook::message(
        FINAL_PHONE_NUMBER_ID,
        FINAL_BUSINESS_ACCOUNT_ID,
        $waId,
        $name,
        'wamid.e2e.'.Str::random(16),
        'text',
        $text,
        now()->getTimestamp(),
    ), JSON_UNESCAPED_UNICODE), PHP_EOL;

    exit(0);
}

if ($command === 'status') {
    [, , $messageId, $status] = $argv + [null, null, '0', 'read'];
    $message = Message::query()->findOrFail((int) $messageId);

    echo json_encode(SimulatedWebhook::status(
        FINAL_PHONE_NUMBER_ID,
        FINAL_BUSINESS_ACCOUNT_ID,
        (string) $message->whatsapp_message_id,
        $status,
        now()->getTimestamp(),
    )), PHP_EOL;

    exit(0);
}

$sales = Department::query()->where('slug', 'e2e-ventas')->firstOrFail();
$ana = User::query()->where('email', 'e2e-ana@example.test')->firstOrFail();

$administrator = User::query()->where('email', 'e2e-administrador@example.test')->first()
    ?? User::factory()->withRole(DefaultRole::Administrator)->create([
        'name' => 'E2E Administrador',
        'email' => 'e2e-administrador@example.test',
        'password' => FINAL_PASSWORD,
    ]);

$appSecret = Str::random(40);
$connection->forceFill([
    'is_active' => true,
    'business_account_id' => FINAL_BUSINESS_ACCOUNT_ID,
    'app_secret' => $appSecret,
])->save();

QuickReply::factory()->create([
    'title' => 'Saludo E2E',
    'shortcut' => 'saludoe2e',
    'body' => 'Hola {contacto.nombre}, gracias por escribirnos.',
]);

$template = MessageTemplate::query()->create([
    'connection_id' => $connection->id,
    'business_account_id' => FINAL_BUSINESS_ACCOUNT_ID,
    'meta_template_id' => '560000000000001',
    'name' => 'seguimiento_e2e',
    'language' => 'es_MX',
    'category' => 'UTILITY',
    'status' => 'APPROVED',
    'parameter_format' => 'POSITIONAL',
    'components' => [
        ['type' => 'BODY', 'text' => 'Hola {{1}}, retomamos tu solicitud.', 'example' => ['body_text' => [['Ana']]]],
    ],
    'last_synced_at' => now(),
]);

$receive = function (string $waId, string $name, string $text, int $minutesAgo): Conversation {
    $receipts = app(ProcessInboundMessages::class)->handle(SimulatedWebhook::message(
        FINAL_PHONE_NUMBER_ID,
        FINAL_BUSINESS_ACCOUNT_ID,
        $waId,
        $name,
        'wamid.e2e.'.Str::random(16),
        'text',
        $text,
        now()->subMinutes($minutesAgo)->getTimestamp(),
    ));

    return $receipts[0]->conversation;
};

app(ChangeAgentAvailability::class)->handle($ana, AgentAvailability::Available);

// A customer who wrote 26 hours ago: the 24-hour window is closed.
$closed = $receive('5215511114444', 'Cliente Ventana Cerrada', 'Escribí ayer', 26 * 60);
app(AcceptConversation::class)->handle($closed->refresh(), $ana->refresh());

// A reply that failed because WhatsApp was unavailable (transport "unavailable").
$retry = $receive('5215511115555', 'Cliente Reintento', 'Sigo esperando', 5);
app(AcceptConversation::class)->handle($retry->refresh(), $ana->refresh());
config(['whatsapp.transport' => 'unavailable']);
app()->forgetInstance(WhatsAppTransport::class);
$failed = app(QueueOutboundMessage::class)->handle($retry->refresh(), $ana->refresh(), 'Te confirmo tu pedido', (string) Str::uuid());

app(ChangeAgentAvailability::class)->handle($ana->refresh(), AgentAvailability::Unavailable);

// A conversation of another department: Ventas agents must never reach it.
$support = Department::query()->where('slug', 'e2e-soporte')->firstOrFail();
$superAdministrator = User::query()->where('email', 'e2e-admin@example.test')->firstOrFail();
$private = $receive('5215511116666', 'Cliente Soporte Privado', 'Tengo una falla', 3);
app(TransferConversation::class)->handle($private->refresh(), $support, null, $superAdministrator, 'Caso técnico');

echo json_encode([
    'database' => $database,
    'webhook_key' => $connection->webhook_key,
    'app_secret' => $appSecret,
    'administrator' => $administrator->email,
    'template' => $template->name,
    'conversations' => ['closed' => $closed->id, 'retry' => $retry->id, 'private' => $private->id],
    'failed_message' => ['id' => $failed->id, 'status' => $failed->refresh()->status->value, 'error' => $failed->error_code],
]), PHP_EOL;
