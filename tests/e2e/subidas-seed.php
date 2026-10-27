<?php

/*
| Data of the upload chain scenario (tests/e2e/subidas.mjs), on top of
| tests/e2e/increment-b-seed.php (already run by tests/e2e/run.php: E2E Ana
| in E2E Ventas and the "E2E WhatsApp Ventas" connection, Phone number ID
| 880000000000002).
|
| It adds one WhatsApp conversation assigned to E2E Ana with the customer
| service window open (a real inbound message). It only touches the
| testing database (chatmanager_testing) and refuses any other one.
| SIMULACIÓN: nothing is sent to Meta; the connection gets throwaway
| credentials and the scenario points META_GRAPH_BASE_URL at
| tests/e2e/fake-graph.php.
|
|   php tests/e2e/subidas-seed.php prepare            -> {"conversation", "thread"}
|       (also a direct internal chat between E2E Ana and E2E Beto)
|   php tests/e2e/subidas-seed.php messages <conversation>
|       -> [{"id", "type", "status", "body", "whatsapp_message_id",
|            "attachments": [{"file_name", "size_bytes", "sha256",
|            "whatsapp_media_id", "stored_bytes"}]}]  (outbound, oldest first)
|   php tests/e2e/subidas-seed.php team <thread>
|       -> [{"id", "author", "body", "attachments": [{"name", "size_bytes", "sha256", "stored_bytes"}]}]
|   php tests/e2e/subidas-seed.php cleanup <conversation> [<thread>]
|       -> {"deleted"}  (removes the stored copies of the scenario's files)
*/

use App\Actions\Conversations\ProcessInboundMessages;
use App\Actions\TeamChat\StartDirectThread;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Models\Connection;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\TeamChat\TeamAttachment;
use App\Models\TeamChat\TeamMessage;
use App\Models\User;
use App\Services\Meta\SimulatedWebhook;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

const SUBIDAS_PHONE_NUMBER_ID = '880000000000002';
const SUBIDAS_ACCOUNT_ID = '990000000000002';

$command = $argv[1] ?? 'prepare';
$outbound = fn (int $conversation) => Message::query()
    ->where('conversation_id', $conversation)
    ->where('direction', MessageDirection::Outbound)
    ->with('attachments')
    ->orderBy('id');

if ($command === 'messages') {
    echo json_encode($outbound((int) ($argv[2] ?? 0))->get()->map(fn (Message $message): array => [
        'id' => $message->id,
        'type' => $message->type->value,
        'status' => $message->status->value,
        'body' => $message->body,
        'whatsapp_message_id' => $message->whatsapp_message_id,
        'error_code' => $message->error_code,
        'attachments' => $message->attachments->map(fn (MessageAttachment $attachment): array => [
            'file_name' => $attachment->file_name,
            'size_bytes' => $attachment->size_bytes,
            'sha256' => $attachment->sha256,
            'whatsapp_media_id' => $attachment->whatsapp_media_id,
            'stored_bytes' => $attachment->path !== null && Storage::disk($attachment->disk)->exists($attachment->path)
                ? Storage::disk($attachment->disk)->size($attachment->path)
                : null,
        ])->all(),
    ])->all(), JSON_UNESCAPED_UNICODE), PHP_EOL;

    exit(0);
}

if ($command === 'team') {
    echo json_encode(TeamMessage::query()
        ->where('team_thread_id', (int) ($argv[2] ?? 0))
        ->with(['attachments', 'author'])
        ->orderBy('id')
        ->get()
        ->map(fn (TeamMessage $message): array => [
            'id' => $message->id,
            'author' => $message->author?->email,
            'body' => $message->body,
            'attachments' => $message->attachments->map(fn (TeamAttachment $attachment): array => [
                'name' => $attachment->original_name,
                'size_bytes' => $attachment->size_bytes,
                'sha256' => $attachment->sha256,
                'stored_bytes' => $attachment->path !== null && Storage::disk($attachment->disk)->exists($attachment->path)
                    ? Storage::disk($attachment->disk)->size($attachment->path)
                    : null,
            ])->all(),
        ])->all(), JSON_UNESCAPED_UNICODE), PHP_EOL;

    exit(0);
}

if ($command === 'cleanup') {
    $deleted = 0;

    $teamFiles = TeamAttachment::query()->whereIn('team_message_id', TeamMessage::query()->where('team_thread_id', (int) ($argv[3] ?? 0))->select('id'))->get();

    foreach ($teamFiles as $attachment) {
        if ($attachment->path !== null && Storage::disk($attachment->disk)->delete($attachment->path)) {
            $deleted++;
        }
    }

    foreach ($outbound((int) ($argv[2] ?? 0))->get() as $message) {
        foreach ($message->attachments as $attachment) {
            if ($attachment->path !== null && Storage::disk($attachment->disk)->delete($attachment->path)) {
                $deleted++;
            }
        }
    }

    echo json_encode(['deleted' => $deleted]), PHP_EOL;

    exit(0);
}

if ($command !== 'prepare') {
    fwrite(STDERR, "Unknown command [{$command}].".PHP_EOL);
    exit(1);
}

$ana = User::query()->where('email', 'e2e-ana@example.test')->firstOrFail();
$whatsapp = Connection::query()->where('phone_number_id', SUBIDAS_PHONE_NUMBER_ID)->firstOrFail();
// Throwaway values: without stored credentials a connection cannot send.
$whatsapp->forceFill([
    'is_active' => true,
    'business_account_id' => $whatsapp->business_account_id ?? SUBIDAS_ACCOUNT_ID,
    'access_token' => $whatsapp->access_token ?? 'E2E-SIMULATED-'.Str::random(32),
    'app_secret' => $whatsapp->app_secret ?? Str::random(40),
    'credentials_error' => null,
])->save();

$receipts = app(ProcessInboundMessages::class)->handle(SimulatedWebhook::message(
    SUBIDAS_PHONE_NUMBER_ID,
    (string) $whatsapp->business_account_id,
    '5215511160001',
    'Cliente Subidas',
    'wamid.e2e.'.Str::random(16),
    'text',
    'Hola, les mando unos archivos',
    now()->getTimestamp(),
));
$conversation = $receipts[0]->conversation->refresh();
$conversation->forceFill([
    'status' => ConversationStatus::Open,
    'assigned_user_id' => $ana->id,
    'assigned_at' => now(),
])->save();

$thread = app(StartDirectThread::class)->handle($ana, User::query()->where('email', 'e2e-beto@example.test')->firstOrFail());

echo json_encode(['conversation' => $conversation->id, 'thread' => $thread->id]), PHP_EOL;
