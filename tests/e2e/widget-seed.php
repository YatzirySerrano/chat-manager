<?php

/*
| Data of the web chat widget scenario (tests/e2e/widget.mjs), on top of
| tests/e2e/increment-b-seed.php (already run by tests/e2e/run.php: E2E Ana
| and Beto in E2E Ventas, the superadministrator).
|
| Two web chat connections routed to E2E Ventas:
|   - "E2E Chat web Tienda": its widget allows the shop's host page origin
|     (http://tienda.test:{E2E_HOST_PORT}) and nothing else;
|   - "E2E Chat web Otra marca": allows only https://otra-marca.test, so it
|     never mounts on the shop's page nor on http://intruso.test;
|   - "E2E Chat web Socios": the shop's origin with identity_mode =
|     required; its identity secret is printed so the scenario can sign
|     identities like the host's backend would (testing database only).
|
| It only touches the testing database (chatmanager_testing) and refuses any
| other one. SIMULACIÓN: nothing is sent to or received from Meta.
|
|   php tests/e2e/widget-seed.php prepare <shop origin>
|   php tests/e2e/widget-seed.php check <connection id>    -> conversations, messages, sessions
*/

use App\Enums\WebChatIdentityMode;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use App\Models\WebChatSession;
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

$command = $argv[1] ?? 'prepare';

if ($command === 'check') {
    $connectionId = (int) ($argv[2] ?? 0);
    $conversations = Conversation::query()
        ->with(['contact', 'messages' => fn ($query) => $query->orderBy('id')])
        ->where('connection_id', $connectionId)
        ->orderBy('id')
        ->get();

    echo json_encode([
        'conversations' => $conversations->map(fn (Conversation $conversation): array => [
            'id' => $conversation->id,
            'status' => $conversation->status->value,
            'contact' => $conversation->contact->name,
            'source' => $conversation->contact->source?->value,
            'messages' => $conversation->messages->map(fn (Message $message): array => [
                'direction' => $message->direction->value,
                'status' => $message->status->value,
                'body' => $message->body,
                'provider_id' => $message->whatsapp_message_id,
            ])->all(),
        ])->all(),
        'sessions' => WebChatSession::query()->where('connection_id', $connectionId)->get(['public_id', 'origin', 'revoked_at'])->map(fn (WebChatSession $session): array => [
            'origin' => $session->origin,
            'revoked' => $session->revoked_at !== null,
        ])->all(),
    ], JSON_UNESCAPED_UNICODE), PHP_EOL;

    exit(0);
}

if ($command !== 'prepare') {
    fwrite(STDERR, "Unknown command [{$command}].".PHP_EOL);
    exit(1);
}

$shopOrigin = (string) ($argv[2] ?? 'http://tienda.test');
$sales = Department::query()->where('slug', 'e2e-ventas')->firstOrFail();

$widgetConnection = function (string $name, array $origins, array $appearance) use ($sales): Connection {
    $connection = Connection::factory()->webChat()->create(['name' => $name, 'default_department_id' => $sales->id]);
    $connection->departments()->syncWithoutDetaching([$sales->id]);
    $connection->webChatWidget()->firstOrFail()->forceFill(['allowed_origins' => $origins, ...$appearance])->save();

    return $connection->load('webChatWidget');
};

$shop = $widgetConnection('E2E Chat web Tienda', [$shopOrigin], [
    'title' => 'Tienda Demo',
    'subtitle' => 'Respondemos en minutos',
    'welcome_text' => '¡Hola! ¿En qué te ayudamos?',
    'accent_color' => '#7c3aed',
    'launcher_label' => 'Chatea con nosotros',
]);
$other = $widgetConnection('E2E Chat web Otra marca', ['https://otra-marca.test'], ['title' => 'Otra marca']);
$members = $widgetConnection('E2E Chat web Socios', [$shopOrigin], [
    'title' => 'Socios',
    'identity_mode' => WebChatIdentityMode::Required,
]);

$ana = User::query()->where('email', 'e2e-ana@example.test')->firstOrFail();

echo json_encode([
    'shop' => ['id' => $shop->id, 'key' => $shop->webChatWidget->public_key, 'origins' => $shop->webChatWidget->allowed_origins],
    'other' => ['id' => $other->id, 'key' => $other->webChatWidget->public_key, 'origins' => $other->webChatWidget->allowed_origins],
    'members' => ['id' => $members->id, 'key' => $members->webChatWidget->public_key, 'secret' => $members->webChatWidget->identity_secret],
], JSON_UNESCAPED_UNICODE), PHP_EOL;
