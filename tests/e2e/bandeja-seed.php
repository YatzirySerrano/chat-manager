<?php

/*
| Data for the inbox scenario (tests/e2e/bandeja.mjs), on top of
| tests/e2e/increment-b-seed.php (already run by tests/e2e/run.php:
| superadministrator, Ana and Beto in E2E Ventas, Carla in E2E Soporte and
| the "E2E WhatsApp Ventas" connection).
|
| It adds a SECOND WhatsApp connection (the only channel the application
| has) that also routes to E2E Ventas, so the inbox shows conversations of
| both numbers, the "Conexión" filter appears and the same customer can have
| one open conversation per connection.
|
| It only touches the testing database (chatmanager_testing) and refuses any
| other one.
|
| SIMULACIÓN: nothing is sent to or received from Meta. Both connections get
| throwaway credentials and app secrets that only exist in
| chatmanager_testing for this run. Customer messages of "prepare" go through
| the real ProcessInboundMessages action; the bodies printed by "message"
| are signed by the scenario and posted to the local webhook. Replies go
| through WHATSAPP_TRANSPORT=simulated.
|
| Run with APP_ENV=testing so .env.testing is loaded:
|
|   php tests/e2e/bandeja-seed.php prepare
|   php tests/e2e/bandeja-seed.php message <a|b> <wa_id> <profile name> <text> [seconds ago]
|   php tests/e2e/bandeja-seed.php pending-jobs
*/

use App\Actions\Conversations\AcceptConversation;
use App\Actions\Conversations\CloseConversation;
use App\Actions\Conversations\MarkConversationRead;
use App\Actions\Conversations\ProcessInboundMessages;
use App\Models\Connection;
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

/**
 * The two WhatsApp numbers of the scenario: "a" is the connection of
 * increment-b-seed.php, "b" the one added here.
 *
 * @var array<string, array{phone_number_id: string, business_account_id: string, name: string, display: string}>
 */
const BANDEJA_CONNECTIONS = [
    'a' => ['phone_number_id' => '880000000000002', 'business_account_id' => '990000000000002', 'name' => 'E2E WhatsApp Ventas', 'display' => '+52 55 0000 0002'],
    'b' => ['phone_number_id' => '880000000000003', 'business_account_id' => '990000000000003', 'name' => 'E2E WhatsApp Sucursal', 'display' => '+52 55 0000 0003'],
];

$command = $argv[1] ?? 'prepare';

$body = fn (string $connection, string $waId, string $name, string $text, ?int $timestamp = null): array => SimulatedWebhook::message(
    BANDEJA_CONNECTIONS[$connection]['phone_number_id'],
    BANDEJA_CONNECTIONS[$connection]['business_account_id'],
    $waId,
    $name,
    'wamid.e2e.'.Str::random(16),
    'text',
    $text,
    $timestamp ?? now()->getTimestamp(),
);

if ($command === 'message') {
    [, , $connection, $waId, $name, $text, $secondsAgo] = $argv + [null, null, 'a', '5215511130000', 'Cliente E2E', 'Hola', '0'];

    if (! isset(BANDEJA_CONNECTIONS[$connection])) {
        fwrite(STDERR, "Unknown connection [{$connection}] (a or b).".PHP_EOL);
        exit(1);
    }

    // Optional: written that many seconds ago (a webhook delivered late).
    echo json_encode($body($connection, $waId, $name, $text, now()->getTimestamp() - (int) $secondsAgo), JSON_UNESCAPED_UNICODE), PHP_EOL;

    exit(0);
}

if ($command === 'pending-jobs') {
    echo DB::table('jobs')->count(), PHP_EOL;

    exit(0);
}

if ($command !== 'prepare') {
    fwrite(STDERR, "Unknown command [{$command}].".PHP_EOL);
    exit(1);
}

$sales = Department::query()->where('slug', 'e2e-ventas')->firstOrFail();
$support = Department::query()->where('slug', 'e2e-soporte')->firstOrFail();

$connections = [];
$secrets = [];

foreach (BANDEJA_CONNECTIONS as $key => $definition) {
    $connection = Connection::query()->firstOrCreate(
        ['phone_number_id' => $definition['phone_number_id']],
        ['name' => $definition['name'], 'display_phone_number' => $definition['display']],
    );
    $secrets[$key] = Str::random(40);

    // Throwaway values: without stored credentials a connection cannot send
    // (Connection::sendingBlockReason) and its webhook cannot be verified.
    $connection->forceFill([
        'name' => $definition['name'],
        'display_phone_number' => $definition['display'],
        'is_active' => true,
        'business_account_id' => $definition['business_account_id'],
        'access_token' => 'E2E-SIMULATED-'.Str::random(32),
        'app_secret' => $secrets[$key],
        'default_department_id' => $sales->id,
        'reopen_window_minutes' => null,
    ])->save();
    $connection->departments()->syncWithoutDetaching([$sales->id, $support->id]);
    $connections[$key] = $connection->refresh();
}

$users = User::query()
    ->with('roles')
    ->whereIn('email', ['e2e-admin@example.test', 'e2e-ana@example.test', 'e2e-beto@example.test', 'e2e-carla@example.test'])
    ->get()
    ->keyBy('email');
$ana = $users['e2e-ana@example.test'];
$beto = $users['e2e-beto@example.test'];

$clock = now()->subMinutes(30)->getTimestamp();

/** A customer message processed by the real inbound action. */
$customerWrites = function (string $connection, string $waId, string $name, string $text) use ($body, &$clock): Conversation {
    $clock += 20;
    $receipts = app(ProcessInboundMessages::class)->handle($body($connection, $waId, $name, $text, $clock));

    return $receipts[0]->conversation->refresh();
};

$conversations = [];

// Waiting in the E2E Ventas queue: Cliente Norte writes to BOTH numbers.
$conversations['norte@a'] = $customerWrites('a', '5215511130001', 'Cliente Norte', 'Hola, ¿tienen envío a Monterrey?');
$conversations['sur@a'] = $customerWrites('a', '5215511130002', 'Cliente Sur', 'Quiero una cotización');
$conversations['centro@a'] = $customerWrites('a', '5215511130003', 'Cliente Centro', 'Buenas tardes');
$conversations['norte@b'] = $customerWrites('b', '5215511130001', 'Cliente Norte', 'Les escribo también a la sucursal');
$conversations['oriente@b'] = $customerWrites('b', '5215511130004', 'Cliente Oriente', '¿Abren el sábado?');

// Ana's own conversations.
$conversations['poniente@b'] = $customerWrites('b', '5215511130005', 'Cliente Poniente', 'Necesito factura');
$conversations['poniente@b'] = app(AcceptConversation::class)->handle($conversations['poniente@b'], $ana);
$customerWrites('b', '5215511130005', 'Cliente Poniente', 'Mi RFC es XAXX010101000');

$conversations['bajio@a'] = $customerWrites('a', '5215511130006', 'Cliente Bajío', 'Gracias por la atención');
$conversations['bajio@a'] = app(AcceptConversation::class)->handle($conversations['bajio@a'], $ana);
app(MarkConversationRead::class)->handle($conversations['bajio@a'], $ana);

$conversations['laguna@a'] = $customerWrites('a', '5215511130010', 'Cliente Laguna', 'Busco refacciones');
$conversations['laguna@a'] = app(AcceptConversation::class)->handle($conversations['laguna@a'], $ana);

$conversations['golfo@b'] = $customerWrites('b', '5215511130007', 'Cliente Golfo', 'Espero su respuesta');
$conversations['golfo@b'] = app(AcceptConversation::class)->handle($conversations['golfo@b'], $ana);

$conversations['istmo@a'] = $customerWrites('a', '5215511130008', 'Cliente Istmo', 'Ya quedó, gracias');
$conversations['istmo@a'] = app(AcceptConversation::class)->handle($conversations['istmo@a'], $ana);
$conversations['istmo@a'] = app(CloseConversation::class)->handle($conversations['istmo@a'], $ana, 'Resuelto');

// Beto's.
$conversations['sierra@a'] = $customerWrites('a', '5215511130009', 'Cliente Sierra', 'Hola Beto');
$conversations['sierra@a'] = app(AcceptConversation::class)->handle($conversations['sierra@a'], $beto);

echo json_encode([
    'database' => $database,
    'connections' => array_map(fn (string $key): array => [
        'id' => $connections[$key]->id,
        'name' => $connections[$key]->name,
        'webhook_key' => $connections[$key]->webhook_key,
        'app_secret' => $secrets[$key],
    ], array_combine(array_keys($connections), array_keys($connections))),
    'conversations' => array_map(fn (Conversation $conversation): array => [
        'id' => $conversation->id,
        'status' => $conversation->status->value,
        'connection' => $conversation->connection_id,
    ], $conversations),
    'agents' => ['ana' => $ana->id, 'beto' => $beto->id],
    'transport' => config('whatsapp.transport'),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
