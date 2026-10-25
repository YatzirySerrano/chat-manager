<?php

/*
| Data of the general inbox scenario (tests/e2e/bandeja-general.mjs), on top
| of tests/e2e/increment-b-seed.php (already run by tests/e2e/run.php:
| superadministrator, Ana and Beto in E2E Ventas, Carla in E2E Soporte and
| the "E2E WhatsApp Ventas" connection).
|
| It adds a WEB CHAT connection ("E2E Chat web Tienda", widget authorized
| for http://tienda.test) that also routes to E2E Ventas, so the general
| inbox mixes WhatsApp and web chat conversations and the "Canal" filter
| appears. Visitor messages go through the real visitor pipeline
| (VisitorSessions + ReceiveVisitorMessage, the same code as
| POST /api/v1/widget/session/messages); WhatsApp messages through the real
| ProcessInboundMessages action.
|
| It only touches the testing database (chatmanager_testing) and refuses any
| other one. SIMULACIÓN: nothing is sent to or received from Meta; the
| WhatsApp connection gets throwaway credentials that only exist in
| chatmanager_testing for this run.
|
|   php tests/e2e/bandeja-general-seed.php prepare
|   php tests/e2e/bandeja-general-seed.php visitor <name> <text>   -> {"conversation", "session"}
|   php tests/e2e/bandeja-general-seed.php whatsapp <wa_id> <name> <text> -> {"conversation"}
|   php tests/e2e/bandeja-general-seed.php check <conversation>     -> status, assignee, accepted events
|   php tests/e2e/bandeja-general-seed.php pending-jobs
*/

use App\Actions\Conversations\AcceptConversation;
use App\Actions\Conversations\ProcessInboundMessages;
use App\Actions\WebChat\ReceiveVisitorMessage;
use App\Enums\ConversationEventType;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\Department;
use App\Models\User;
use App\Models\WebChatWidget;
use App\Services\Meta\SimulatedWebhook;
use App\Services\WebChat\VisitorSessions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

const GENERAL_WHATSAPP_PHONE_NUMBER_ID = '880000000000002';
const GENERAL_WHATSAPP_ACCOUNT_ID = '990000000000002';
const GENERAL_WEB_CONNECTION = 'E2E Chat web Tienda';

$command = $argv[1] ?? 'prepare';

$whatsapp = Connection::query()->where('phone_number_id', GENERAL_WHATSAPP_PHONE_NUMBER_ID)->firstOrFail();

/** A WhatsApp customer message processed by the real inbound action. */
$whatsappWrites = function (string $waId, string $name, string $text): Conversation {
    $receipts = app(ProcessInboundMessages::class)->handle(SimulatedWebhook::message(
        GENERAL_WHATSAPP_PHONE_NUMBER_ID,
        GENERAL_WHATSAPP_ACCOUNT_ID,
        $waId,
        $name,
        'wamid.e2e.'.Str::random(16),
        'text',
        $text,
        now()->getTimestamp(),
    ));

    return $receipts[0]->conversation->refresh();
};

/**
 * A new web chat visitor (a session started from the authorized host page)
 * writing its first message through the visitor pipeline.
 *
 * @return array{conversation: Conversation, session: string}
 */
$visitorWrites = function (string $name, string $text): array {
    $widget = WebChatWidget::query()
        ->whereHas('connection', fn ($query) => $query->where('name', GENERAL_WEB_CONNECTION))
        ->with('connection')
        ->firstOrFail();
    ['session' => $session] = app(VisitorSessions::class)->start($widget, 'http://tienda.test', '127.0.0.1', 'E2E', ['name' => $name], null, 'http://tienda.test/');
    $session->setRelation('connection', $widget->connection);
    $widget->connection->setRelation('webChatWidget', $widget);

    $message = app(ReceiveVisitorMessage::class)->handle($session, (string) Str::uuid(), $text)['message'];

    return ['conversation' => Conversation::query()->findOrFail($message->conversation_id), 'session' => $session->public_id];
};

switch ($command) {
    case 'visitor':
        [, , $name, $text] = $argv + [null, null, 'Visitante E2E', 'Hola'];
        ['conversation' => $conversation, 'session' => $session] = $visitorWrites($name, $text);
        echo json_encode(['conversation' => $conversation->id, 'status' => $conversation->status->value, 'session' => $session]), PHP_EOL;

        exit(0);

    case 'whatsapp':
        [, , $waId, $name, $text] = $argv + [null, null, '5215511140000', 'Cliente E2E', 'Hola'];
        $conversation = $whatsappWrites($waId, $name, $text);
        echo json_encode(['conversation' => $conversation->id, 'status' => $conversation->status->value]), PHP_EOL;

        exit(0);

    case 'check':
        $conversation = Conversation::query()->findOrFail((int) ($argv[2] ?? 0));
        echo json_encode([
            'status' => $conversation->status->value,
            'assigned_user_id' => $conversation->assigned_user_id,
            'accepted_events' => ConversationEvent::query()->where('conversation_id', $conversation->id)->where('type', ConversationEventType::Accepted)->count(),
        ]), PHP_EOL;

        exit(0);

    case 'pending-jobs':
        echo DB::table('jobs')->count(), PHP_EOL;

        exit(0);

    case 'prepare':
        break;

    default:
        fwrite(STDERR, "Unknown command [{$command}].".PHP_EOL);
        exit(1);
}

$sales = Department::query()->where('slug', 'e2e-ventas')->firstOrFail();
$support = Department::query()->where('slug', 'e2e-soporte')->firstOrFail();

// Throwaway values: without stored credentials a connection cannot send.
$whatsapp->forceFill([
    'is_active' => true,
    'business_account_id' => GENERAL_WHATSAPP_ACCOUNT_ID,
    'access_token' => 'E2E-SIMULATED-'.Str::random(32),
    'app_secret' => Str::random(40),
    'default_department_id' => $sales->id,
])->save();

$web = Connection::query()->where('name', GENERAL_WEB_CONNECTION)->first()
    ?? Connection::factory()->webChat()->create(['name' => GENERAL_WEB_CONNECTION]);
$web->forceFill(['is_active' => true, 'default_department_id' => $sales->id])->save();
$web->departments()->syncWithoutDetaching([$sales->id, $support->id]);
$web->webChatWidget()->firstOrFail()->forceFill([
    'allowed_origins' => ['http://tienda.test'],
    'title' => 'Tienda E2E',
])->save();

$users = User::query()->with('roles')->whereIn('email', ['e2e-ana@example.test', 'e2e-beto@example.test'])->get()->keyBy('email');
$ana = $users['e2e-ana@example.test'];
$beto = $users['e2e-beto@example.test'];

foreach ([$ana, $beto] as $agent) {
}

$conversations = [];

// Waiting in the E2E Ventas queue: one per channel.
$conversations['wa-pending'] = $whatsappWrites('5215511140001', 'Cliente WhatsApp Pendiente', 'Hola, ¿tienen envío?');
$conversations['web-pending'] = $visitorWrites('Visitante Pendiente', 'Hola desde la tienda en línea')['conversation'];
$conversations['web-to-accept'] = $visitorWrites('Visitante Por Aceptar', 'Quiero cambiar mi pedido')['conversation'];

// Ana's own: one WhatsApp and one web chat attended ("en espera" was retired).
$conversations['wa-ana'] = app(AcceptConversation::class)->handle($whatsappWrites('5215511140002', 'Cliente WhatsApp Ana', 'Necesito mi factura'), $ana);
$conversations['web-ana-hold'] = app(AcceptConversation::class)->handle($visitorWrites('Visitante En Espera', '¿Me avisan cuando llegue?')['conversation'], $ana);

// Beto's: never in Ana's general inbox.
$conversations['wa-beto'] = app(AcceptConversation::class)->handle($whatsappWrites('5215511140003', 'Cliente WhatsApp Beto', 'Hola Beto'), $beto);

echo json_encode([
    'connections' => ['whatsapp' => ['id' => $whatsapp->id, 'name' => $whatsapp->name], 'web' => ['id' => $web->id, 'name' => $web->name]],
    'conversations' => array_map(fn (Conversation $conversation): array => ['id' => $conversation->id, 'status' => $conversation->status->value], $conversations),
    'agents' => ['ana' => $ana->id, 'beto' => $beto->id],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
