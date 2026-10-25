<?php

/*
| Data for the phase 5 browser scenario (tests/e2e/fase5.mjs), on top of
| tests/e2e/increment-b-seed.php (already run by tests/e2e/run.php:
| superadministrator, Ana and Beto in E2E Ventas, Carla in E2E Soporte and
| the E2E WhatsApp connection).
|
| It only touches the testing database (chatmanager_testing) and refuses any
| other one.
|
| SIMULACIÓN: nothing is sent to or received from Meta. The connection gets
| throwaway credentials and an app secret that only exist in
| chatmanager_testing for this run; the scenario signs the status webhooks
| printed by "status" with that secret and posts them to the local server.
| Replies go through WHATSAPP_TRANSPORT=simulated (with
| WHATSAPP_SIMULATE_STATUSES=0 the statuses only come from those webhooks).
|
| Run with APP_ENV=testing so .env.testing is loaded:
|
|   php tests/e2e/fase5-seed.php prepare
|   php tests/e2e/fase5-seed.php status <message id> <sent|delivered|read>
*/

use App\Actions\Conversations\AcceptConversation;
use App\Actions\Conversations\ProcessInboundMessages;
use App\Actions\Conversations\TransferConversation;
use App\Actions\Users\UpdatePersonalQuickRepliesGrant;
use App\Enums\DefaultRole;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\QuickReply;
use App\Models\Tag;
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

const F5_PHONE_NUMBER_ID = '880000000000002';
const F5_BUSINESS_ACCOUNT_ID = '990000000000002';
const F5_PASSWORD = 'e2e-password';

$command = $argv[1] ?? 'prepare';

if ($command === 'status') {
    [, , $messageId, $status] = $argv + [null, null, '0', 'read'];
    $message = Message::query()->findOrFail((int) $messageId);

    echo json_encode(SimulatedWebhook::status(
        F5_PHONE_NUMBER_ID,
        F5_BUSINESS_ACCOUNT_ID,
        (string) $message->whatsapp_message_id,
        $status,
        now()->getTimestamp(),
    )), PHP_EOL;

    exit(0);
}

if ($command !== 'prepare') {
    fwrite(STDERR, "Unknown command [{$command}].".PHP_EOL);
    exit(1);
}

$connection = Connection::query()->where('phone_number_id', F5_PHONE_NUMBER_ID)->firstOrFail();
$appSecret = Str::random(40);

// Without stored credentials the connection cannot send (Connection::
// sendingBlockReason); these are throwaway values for the simulated transport.
$connection->forceFill([
    'is_active' => true,
    'business_account_id' => F5_BUSINESS_ACCOUNT_ID,
    'access_token' => 'E2E-SIMULATED-'.Str::random(32),
    'app_secret' => $appSecret,
])->save();

$sales = Department::query()->where('slug', 'e2e-ventas')->firstOrFail();
$support = Department::query()->where('slug', 'e2e-soporte')->firstOrFail();
$superAdministrator = User::query()->where('email', 'e2e-admin@example.test')->firstOrFail();
$ana = User::query()->where('email', 'e2e-ana@example.test')->firstOrFail();
$beto = User::query()->where('email', 'e2e-beto@example.test')->firstOrFail();
$carla = User::query()->where('email', 'e2e-carla@example.test')->firstOrFail();

$administrator = User::query()->where('email', 'e2e-administrador@example.test')->first()
    ?? User::factory()->withRole(DefaultRole::Administrator)->create([
        'name' => 'E2E Administrador',
        'email' => 'e2e-administrador@example.test',
        'password' => F5_PASSWORD,
    ]);

$supervisor = User::query()->where('email', 'e2e-supervisor@example.test')->first()
    ?? User::factory()->withRole(DefaultRole::Supervisor)->inDepartments([$sales])->create([
        'name' => 'E2E Supervisora',
        'email' => 'e2e-supervisor@example.test',
        'password' => F5_PASSWORD,
    ]);

// Ana may create personal quick replies (granted per user); Beto may not.
app(UpdatePersonalQuickRepliesGrant::class)->handle($ana->refresh(), true, $superAdministrator);

// A corporate reply, so Beto's picker opens with something in it.
QuickReply::factory()->create([
    'title' => 'Saludo Ventas E2E',
    'shortcut' => 'ventase2e',
    'body' => 'Hola {contacto.nombre}, gracias por escribir a Ventas.',
]);

$tag = fn (string $name, string $slug) => Tag::factory()->create(['name' => $name, 'slug' => $slug, 'is_active' => true]);
$urgent = $tag('E2E Urgente', 'e2e-urgente');
$invoice = $tag('E2E Factura', 'e2e-factura');
$wholesale = $tag('E2E Mayoreo', 'e2e-mayoreo');

$receive = function (string $waId, string $name, string $text, int $minutesAgo = 0): Conversation {
    $receipts = app(ProcessInboundMessages::class)->handle(SimulatedWebhook::message(
        F5_PHONE_NUMBER_ID,
        F5_BUSINESS_ACCOUNT_ID,
        $waId,
        $name,
        'wamid.e2e.'.Str::random(16),
        'text',
        $text,
        now()->subMinutes($minutesAgo)->getTimestamp(),
    ));

    return $receipts[0]->conversation->refresh();
};

$accept = fn (Conversation $conversation, User $agent): Conversation => app(AcceptConversation::class)->handle($conversation->refresh(), $agent->refresh())->refresh();

$attachTags = function (Conversation $conversation, array $tags) use ($ana): void {
    foreach ($tags as $tag) {
        $conversation->tags()->attach($tag->id, ['tagged_by_user_id' => $ana->id, 'created_at' => now()]);
    }
};

// Pending in Ventas: Ana, Beto and the administrators see it (alias).
$aliasConversation = $receive('5215520000001', 'Cliente Oficial Alias', 'Hola, quiero una cotización', 30);

// Pending in Ventas: a provisional contact the Administrador saves.
$provisional = $receive('5215520000002', 'Cliente Provisional E2E', 'Primera vez que escribo', 25);

// Ana's own conversations, with tags for the filter menu.
$tagsBoth = $accept($receive('5215520000003', 'Cliente Etiquetas Ambas', 'Necesito factura urgente', 20), $ana);
$attachTags($tagsBoth, [$urgent, $invoice]);
$tagsUrgent = $accept($receive('5215520000004', 'Cliente Solo Urgente', 'Es urgente', 19), $ana);
$attachTags($tagsUrgent, [$urgent]);
$tagsInvoice = $accept($receive('5215520000005', 'Cliente Solo Factura', 'Mándame la factura', 18), $ana);
$attachTags($tagsInvoice, [$invoice]);
$untagged = $accept($receive('5215520000006', 'Cliente Sin Etiquetas', 'Hola de nuevo', 17), $ana);

// Ana's conversation for replies, delivery statuses, attachments and "/".
$delivery = $accept($receive('5215520000007', 'Cliente Entregas E2E', 'Hola, ¿me confirman el envío?', 5), $ana);

// Beto's own conversation (appearance unaffected, quick replies picker).
$betoConversation = $accept($receive('5215520000008', 'Cliente de Beto', 'Hola Beto', 4), $beto);

// Ana's conversation whose 24-hour window closed (templates are not hers).
$closedWindow = $accept($receive('5215520000009', 'Cliente Ventana Vencida', 'Escribí hace dos días', 26 * 60), $ana);

// Attended in Ventas, then transferred to Soporte (Carla): the Ventas
// supervisor keeps read-only visibility; Ana loses it.
$transferred = $accept($receive('5215520000010', 'Cliente Transferido E2E', 'Tengo una falla técnica', 15), $ana);
app(TransferConversation::class)->handle($transferred->refresh(), $support, $carla->refresh(), $superAdministrator, 'Caso técnico');

// Soporte from the start (never in Ventas, no transfer history): neither
// Ventas agents nor the Ventas supervisor reach it.
$supportOnly = Conversation::factory()->inDepartment($support)->assignedTo($carla)->create([
    'connection_id' => $connection->id,
    'contact_id' => Contact::factory()->create(['name' => 'Cliente Solo Soporte'])->id,
]);

echo json_encode([
    'database' => $database,
    'connection' => $connection->id,
    'webhook_key' => $connection->webhook_key,
    'app_secret' => $appSecret,
    'users' => [
        'administrator' => $administrator->email,
        'supervisor' => $supervisor->email,
        'ana' => $ana->email,
        'beto' => $beto->email,
    ],
    'tags' => ['urgent' => $urgent->name, 'invoice' => $invoice->name, 'wholesale' => $wholesale->name],
    'conversations' => [
        'alias' => $aliasConversation->id,
        'provisional' => $provisional->id,
        'tags_both' => $tagsBoth->id,
        'tags_urgent' => $tagsUrgent->id,
        'tags_invoice' => $tagsInvoice->id,
        'untagged' => $untagged->id,
        'delivery' => $delivery->id,
        'beto' => $betoConversation->id,
        'closed_window' => $closedWindow->id,
        'transferred' => $transferred->id,
        'support_only' => $supportOnly->id,
    ],
    'transport' => config('whatsapp.transport'),
], JSON_UNESCAPED_UNICODE), PHP_EOL;
