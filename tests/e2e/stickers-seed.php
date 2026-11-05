<?php

/*
| Data of the stickers and automatic messages scenario
| (tests/e2e/stickers.mjs), on top of tests/e2e/increment-b-seed.php
| (already run by tests/e2e/run.php: E2E Ana in E2E Ventas and the
| "E2E WhatsApp Ventas" connection).
|
| It adds a WhatsApp conversation assigned to E2E Ana with three customer
| stickers, the files written like DownloadInboundMedia stores them:
|   - static: the static WebP fixture (tests/fixtures/whatsapp);
|   - animated: the animated fixture grown past 500 KiB with an unknown
|     RIFF chunk (as the large animated stickers seen in production);
|   - refused: unavailable with error_code too_large, without a file.
| The connection gets its away message enabled while the platform applies
| no schedule (continuous attention), the case the diagnostics must warn of.
|
| It only touches the testing database (chatmanager_testing) and refuses any
| other one. SIMULACIÓN: nothing is sent to or received from Meta.
|
|   php tests/e2e/stickers-seed.php prepare
|       -> {"conversation", "connection", "static", "animated", "refused"}
*/

use App\Actions\Conversations\ProcessInboundMessages;
use App\Enums\AttachmentStatus;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Models\Connection;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\Meta\SimulatedWebhook;
use App\Services\PlatformSettings;
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

const STICKERS_PHONE_NUMBER_ID = '880000000000002';

if (($argv[1] ?? 'prepare') !== 'prepare') {
    fwrite(STDERR, 'Unknown command.'.PHP_EOL);
    exit(1);
}

$ana = User::query()->where('email', 'e2e-ana@example.test')->firstOrFail();
$whatsapp = Connection::query()->where('phone_number_id', STICKERS_PHONE_NUMBER_ID)->firstOrFail();
$whatsapp->forceFill([
    'is_active' => true,
    'access_token' => $whatsapp->access_token ?? 'E2E-SIMULATED-'.Str::random(32),
    'app_secret' => $whatsapp->app_secret ?? Str::random(40),
    'away_enabled' => true,
    'away_message' => 'Estamos fuera de horario; te respondemos al abrir.',
])->save();
app(PlatformSettings::class)->update(['business_hours' => ['enabled' => false]]);

$receipts = app(ProcessInboundMessages::class)->handle(SimulatedWebhook::message(
    STICKERS_PHONE_NUMBER_ID,
    (string) $whatsapp->business_account_id,
    '5215511160001',
    'Cliente Stickers',
    'wamid.e2e.'.Str::random(16),
    'text',
    'Hola, les mando unos stickers',
    now()->getTimestamp(),
));
$conversation = $receipts[0]->conversation->refresh();
$conversation->forceFill(['status' => ConversationStatus::Open, 'assigned_user_id' => $ana->id, 'assigned_at' => now()])->save();

$static = (string) file_get_contents(__DIR__.'/../fixtures/whatsapp/sticker-static.webp');
$animated = (string) file_get_contents(__DIR__.'/../fixtures/whatsapp/sticker-animated.webp');
$padding = str_repeat("\0", 700_000);
$body = substr($animated, 12).'XPAD'.pack('V', strlen($padding)).$padding;
$largeAnimated = 'RIFF'.pack('V', strlen($body) + 4).'WEBP'.$body;

$sticker = function (?string $bytes, bool $animated, ?string $errorCode = null) use ($conversation): MessageAttachment {
    $message = Message::query()->create([
        'conversation_id' => $conversation->id,
        'whatsapp_message_id' => 'wamid.e2e.sticker.'.Str::random(16),
        'direction' => MessageDirection::Inbound,
        'type' => MessageType::Sticker,
        'status' => MessageStatus::Received,
        'metadata' => ['content' => ['id' => 'e2e-'.Str::random(8), 'mime_type' => 'image/webp', 'animated' => $animated]],
    ]);
    $path = null;

    if ($bytes !== null) {
        $path = 'media/e2e/'.Str::uuid();
        Storage::disk('local')->put($path, $bytes);
    }

    $attachment = new MessageAttachment([
        'message_id' => $message->id,
        'disk' => 'local',
        'path' => $path,
        'mime_type' => 'image/webp',
        'file_name' => null,
        'size_bytes' => $bytes === null ? null : strlen($bytes),
    ]);
    $attachment->forceFill([
        'status' => $bytes === null ? AttachmentStatus::Unavailable : AttachmentStatus::Available,
        'error_code' => $errorCode,
        'downloaded_at' => $bytes === null ? null : now(),
    ])->save();

    return $attachment;
};

echo json_encode([
    'conversation' => $conversation->id,
    'connection' => $whatsapp->id,
    'static' => $sticker($static, false)->id,
    'animated' => $sticker($largeAnimated, true)->id,
    'refused' => $sticker(null, true, 'too_large')->id,
    'animated_bytes' => strlen($largeAnimated),
]), PHP_EOL;
