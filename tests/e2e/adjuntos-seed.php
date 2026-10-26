<?php

/*
| Data of the attachment picker scenario (tests/e2e/adjuntos.mjs), on top of
| tests/e2e/increment-b-seed.php (already run by tests/e2e/run.php: E2E Ana
| in E2E Ventas and the "E2E WhatsApp Ventas" connection).
|
| It adds a WEB CHAT connection ("E2E Chat web Adjuntos") and three
| conversations assigned to E2E Ana:
|   - whatsapp: customer service window open, ready to reply, with two
|     unavailable inbound attachments (a history file that never arrived,
|     error_code historical_missing, and one whose storage did not answer,
|     storage_unavailable), both without a stored path;
|   - web: a web chat visitor (real visitor pipeline);
|   - conflict: another WhatsApp conversation that "close" closes behind the
|     agent's back, so the next send is refused by the server.
| Messages and attachments are written directly (no notifications, no jobs).
|
| It only touches the testing database (chatmanager_testing) and refuses any
| other one. SIMULACIÓN: nothing is sent to or received from Meta; the
| WhatsApp connection gets throwaway credentials that only exist in
| chatmanager_testing for this run.
|
|   php tests/e2e/adjuntos-seed.php prepare
|       -> {"whatsapp", "web", "conflict", "historical", "storage"}
|   php tests/e2e/adjuntos-seed.php close <conversation>  -> {"status"}
|   php tests/e2e/adjuntos-seed.php last <conversation>
|       -> {"type", "attachments": [{"file_name", "delivery_method", "size_bytes"}]}
*/

use App\Actions\Conversations\ProcessInboundMessages;
use App\Actions\WebChat\ReceiveVisitorMessage;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
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

const ADJUNTOS_PHONE_NUMBER_ID = '880000000000002';
const ADJUNTOS_ACCOUNT_ID = '990000000000002';
const ADJUNTOS_WEB_CONNECTION = 'E2E Chat web Adjuntos';

$command = $argv[1] ?? 'prepare';

if ($command === 'close') {
    $conversation = Conversation::query()->findOrFail((int) ($argv[2] ?? 0));
    $conversation->forceFill(['status' => ConversationStatus::Closed, 'closed_at' => now()])->save();
    echo json_encode(['status' => $conversation->refresh()->status->value]), PHP_EOL;

    exit(0);
}

if ($command === 'last') {
    $message = Message::query()
        ->where('conversation_id', (int) ($argv[2] ?? 0))
        ->where('direction', MessageDirection::Outbound)
        ->with('attachments')
        ->latest('id')
        ->first();
    echo json_encode([
        'type' => $message?->type->value,
        'attachments' => $message?->attachments->map(fn (MessageAttachment $attachment): array => [
            'file_name' => $attachment->file_name,
            'delivery_method' => $attachment->getAttribute('delivery_method') instanceof BackedEnum
                ? $attachment->getAttribute('delivery_method')->value
                : $attachment->getAttribute('delivery_method'),
            'size_bytes' => $attachment->size_bytes,
        ])->all() ?? [],
    ], JSON_UNESCAPED_UNICODE), PHP_EOL;

    exit(0);
}

if ($command !== 'prepare') {
    fwrite(STDERR, "Unknown command [{$command}].".PHP_EOL);
    exit(1);
}

$sales = Department::query()->where('slug', 'e2e-ventas')->firstOrFail();
$ana = User::query()->where('email', 'e2e-ana@example.test')->firstOrFail();

$whatsapp = Connection::query()->where('phone_number_id', ADJUNTOS_PHONE_NUMBER_ID)->firstOrFail();
// Throwaway values: without stored credentials a connection cannot send.
$whatsapp->forceFill([
    'is_active' => true,
    'business_account_id' => $whatsapp->business_account_id ?? ADJUNTOS_ACCOUNT_ID,
    'access_token' => $whatsapp->access_token ?? 'E2E-SIMULATED-'.Str::random(32),
    'app_secret' => $whatsapp->app_secret ?? Str::random(40),
])->save();

/** A WhatsApp customer message (real inbound action), then assigned to Ana. */
$whatsappWrites = function (string $waId, string $name, string $text) use ($whatsapp, $ana): Conversation {
    $receipts = app(ProcessInboundMessages::class)->handle(SimulatedWebhook::message(
        ADJUNTOS_PHONE_NUMBER_ID,
        (string) $whatsapp->business_account_id,
        $waId,
        $name,
        'wamid.e2e.'.Str::random(16),
        'text',
        $text,
        now()->getTimestamp(),
    ));
    $conversation = $receipts[0]->conversation->refresh();
    $conversation->forceFill([
        'status' => ConversationStatus::Open,
        'assigned_user_id' => $ana->id,
        'assigned_at' => now(),
    ])->save();

    return $conversation;
};

$main = $whatsappWrites('5215511150001', 'Cliente Adjuntos WhatsApp', 'Hola, les mando unos archivos');
$conflict = $whatsappWrites('5215511150002', 'Cliente Adjuntos Conflicto', 'Hola, ¿me ayudan?');

$unavailable = function (string $name, string $mime, string $code) use ($main): MessageAttachment {
    $message = Message::query()->create([
        'conversation_id' => $main->id,
        'whatsapp_message_id' => 'wamid.e2e.attachment.'.Str::random(16),
        'direction' => MessageDirection::Inbound,
        'type' => MessageType::Document,
        'status' => MessageStatus::Received,
    ]);

    $attachment = new MessageAttachment([
        'message_id' => $message->id,
        'disk' => 'local',
        'path' => null,
        'mime_type' => $mime,
        'file_name' => $name,
        'size_bytes' => 52_000,
    ]);
    $attachment->forceFill(['status' => 'unavailable', 'error_code' => $code])->save();

    return $attachment;
};

$historical = $unavailable('contrato-historico-2023.pdf', 'application/pdf', 'historical_missing');
$storage = $unavailable('cotizacion-almacenamiento.pdf', 'application/pdf', 'storage_unavailable');

$web = Connection::query()->where('name', ADJUNTOS_WEB_CONNECTION)->first()
    ?? Connection::factory()->webChat()->create(['name' => ADJUNTOS_WEB_CONNECTION]);
$web->forceFill(['is_active' => true, 'default_department_id' => $sales->id])->save();
$web->departments()->syncWithoutDetaching([$sales->id]);
$widget = $web->webChatWidget()->firstOrFail();
$widget->forceFill(['allowed_origins' => ['http://tienda.test'], 'title' => 'Tienda Adjuntos'])->save();

['session' => $session] = app(VisitorSessions::class)->start($widget, 'http://tienda.test', '127.0.0.1', 'E2E', ['name' => 'Visitante Adjuntos'], null, 'http://tienda.test/');
$session->setRelation('connection', $web);
$web->setRelation('webChatWidget', $widget);
$visitorMessage = app(ReceiveVisitorMessage::class)->handle($session, (string) Str::uuid(), 'Hola, ¿les puedo mandar un ZIP?')['message'];
$webConversation = Conversation::query()->findOrFail($visitorMessage->conversation_id);
$webConversation->forceFill([
    'status' => ConversationStatus::Open,
    'assigned_user_id' => $ana->id,
    'assigned_at' => now(),
])->save();

echo json_encode([
    'whatsapp' => $main->id,
    'web' => $webConversation->id,
    'conflict' => $conflict->id,
    'historical' => $historical->id,
    'storage' => $storage->id,
], JSON_UNESCAPED_UNICODE), PHP_EOL;
