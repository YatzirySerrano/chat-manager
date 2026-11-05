<?php

use App\Actions\Conversations\ProcessInboundMessages;
use App\Enums\AttachmentStatus;
use App\Enums\MessageType;
use App\Http\Resources\ConversationMessageResource;
use App\Jobs\DownloadInboundMedia;
use App\Models\Connection;
use App\Models\MessageAttachment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
| WhatsApp stickers: WebP files (static or animated) announced by the
| webhook and downloaded like any other customer media.
*/

const STICKER_TOKEN = 'EAAGstickerTestToken00000000000000000000000000000000000';

function stickerBytes(string $name): string
{
    return (string) file_get_contents(base_path("tests/fixtures/whatsapp/sticker-{$name}.webp"));
}

/**
 * The animated fixture grown to $bytes with an unknown RIFF chunk (WebP
 * readers skip chunks they do not know), as a large animated sticker.
 */
function largeAnimatedSticker(int $bytes): string
{
    $webp = stickerBytes('animated');
    $padding = str_repeat("\0", $bytes - strlen($webp) - 8);
    $body = substr($webp, 12).'XPAD'.pack('V', strlen($padding)).$padding;

    return 'RIFF'.pack('V', strlen($body) + 4).'WEBP'.$body;
}

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    Storage::fake('local');
    config(['services.meta.graph_version' => 'v26.0', 'filesystems.media_disk' => 'local']);
    $this->connection = Connection::factory()->create(['access_token' => STICKER_TOKEN]);
    $this->receiveSticker = function (string $mediaId, bool $animated = false): MessageAttachment {
        $payload = whatsappWebhook($this->connection, [[
            'from' => '5215512345678',
            'id' => 'wamid.'.fake()->unique()->numerify('########'),
            'timestamp' => (string) now()->getTimestamp(),
            'type' => 'sticker',
            'sticker' => ['id' => $mediaId, 'mime_type' => 'image/webp', 'sha256' => 'abc', 'animated' => $animated],
        ]]);

        return app(ProcessInboundMessages::class)->handle($payload)[0]->message->attachments()->sole();
    };
    $this->fakeMeta = function (array $files): void {
        $fakes = [];

        foreach ($files as $mediaId => $bytes) {
            $url = "https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid={$mediaId}&ext=1761000000&hash=ATsigned";
            $fakes["graph.facebook.com/v26.0/{$mediaId}"] = Http::response(['messaging_product' => 'whatsapp', 'url' => $url, 'mime_type' => 'image/webp', 'sha256' => 'abc', 'file_size' => strlen($bytes), 'id' => (string) $mediaId]);
            $fakes["lookaside.fbsbx.com/whatsapp_business/attachments/?mid={$mediaId}*"] = Http::response($bytes, 200, ['Content-Type' => 'image/webp']);
        }

        Http::fake($fakes);
    };
});

test('static and animated stickers are downloaded and stored as WebP', function (string $file, bool $animated) {
    $attachment = ($this->receiveSticker)('2001', $animated);
    ($this->fakeMeta)(['2001' => stickerBytes($file)]);

    app()->call([new DownloadInboundMedia($attachment->id), 'handle']);

    expect($attachment->refresh())
        ->status->toBe(AttachmentStatus::Available)
        ->mime_type->toBe('image/webp')
        ->size_bytes->toBe(strlen(stickerBytes($file)))
        ->and($attachment->message->type)->toBe(MessageType::Sticker)
        ->and(Storage::disk('local')->get((string) $attachment->path))->toBe(stickerBytes($file));
})->with([
    'static' => ['static', false],
    'animated' => ['animated', true],
]);

test('several consecutive stickers are each downloaded once', function () {
    $attachments = collect(['3001', '3002', '3003'])->map(fn (string $id): MessageAttachment => ($this->receiveSticker)($id, $id === '3002'));
    ($this->fakeMeta)(['3001' => stickerBytes('static'), '3002' => stickerBytes('animated'), '3003' => stickerBytes('static')]);

    $attachments->each(fn (MessageAttachment $attachment) => app()->call([new DownloadInboundMedia($attachment->id), 'handle']));

    expect($attachments->map(fn (MessageAttachment $attachment): string => $attachment->refresh()->status->value)->all())
        ->toBe(['available', 'available', 'available'])
        ->and(Storage::disk('local')->allFiles())->toHaveCount(3);
});

test('a sticker that cannot be kept is unavailable with its own reason and a diagnostic log without secrets', function (Closure $fakes, string $reason) {
    Log::spy();
    $attachment = ($this->receiveSticker)('4001', true);
    Http::fake($fakes());

    app()->call([new DownloadInboundMedia($attachment->id), 'handle']);

    expect($attachment->refresh())
        ->status->toBe(AttachmentStatus::Unavailable)
        ->error_code->toBe($reason)
        ->path->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'Customer media marked unavailable.'
        && $context['attachment_id'] === $attachment->id
        && $context['message_type'] === 'sticker'
        && $context['reason'] === $reason
        && $context['animated'] === true
        && ! str_contains((string) json_encode($context), STICKER_TOKEN)
        && ! str_contains((string) json_encode($context), 'hash=ATsigned'))->once();
})->with([
    'not really WebP' => [fn () => [
        'graph.facebook.com/*' => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=4001&hash=ATsigned', 'mime_type' => 'image/webp', 'file_size' => 27]),
        'lookaside.fbsbx.com/*' => Http::response('<?php echo "not a sticker";', 200, ['Content-Type' => 'image/webp']),
    ], 'type_not_allowed'],
    'media id Meta no longer keeps' => [fn () => ['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Unsupported get request.', 'code' => 100]], 400)], 'expired'],
    'token rejected' => [fn () => ['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'code' => 190]], 401)], 'credentials_rejected'],
]);

test('a temporary Meta error is retried and the sticker is stored on the next attempt', function () {
    $attachment = ($this->receiveSticker)('5001');
    Http::fake([
        'graph.facebook.com/v26.0/5001' => Http::sequence()
            ->push(['error' => ['message' => 'Service temporarily unavailable', 'code' => 2]], 503)
            ->push(['url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=5001&hash=ATsigned', 'mime_type' => 'image/webp', 'file_size' => strlen(stickerBytes('static'))]),
        'lookaside.fbsbx.com/*' => Http::response(stickerBytes('static'), 200, ['Content-Type' => 'image/webp']),
    ]);

    $job = (new DownloadInboundMedia($attachment->id))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertReleased();
    expect($attachment->refresh()->status)->toBe(AttachmentStatus::Pending);

    app()->call([new DownloadInboundMedia($attachment->id), 'handle']);

    expect($attachment->refresh())
        ->status->toBe(AttachmentStatus::Available)
        ->mime_type->toBe('image/webp')
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
});

test('unavailable media is only summarized without --apply, and --apply queues just the recoverable rows', function () {
    $recoverable = ($this->receiveSticker)('6001');
    $tooOld = ($this->receiveSticker)('6002');
    $credentials = ($this->receiveSticker)('6003');
    MessageAttachment::query()->whereKey([$recoverable->id, $tooOld->id])->update(['status' => AttachmentStatus::Unavailable, 'error_code' => 'too_large']);
    MessageAttachment::query()->whereKey($tooOld->id)->update(['created_at' => now()->subDays(8)]);
    MessageAttachment::query()->whereKey($credentials->id)->update(['status' => AttachmentStatus::Unavailable, 'error_code' => 'credentials_rejected']);

    $downloads = fn (): int => Queue::pushed(DownloadInboundMedia::class)->count();
    $received = $downloads();

    $this->artisan('attachments:retry-unavailable', ['--type' => 'sticker'])
        ->expectsOutputToContain('Se podrían volver a descargar 1 adjuntos')
        ->assertSuccessful();

    expect($downloads())->toBe($received)
        ->and($recoverable->refresh()->status)->toBe(AttachmentStatus::Unavailable);

    $this->artisan('attachments:retry-unavailable', ['--type' => 'sticker', '--apply' => true])->assertSuccessful();

    expect($downloads())->toBe($received + 1);
    Queue::assertPushed(DownloadInboundMedia::class, fn (DownloadInboundMedia $job): bool => $job->attachmentId === $recoverable->id);
    expect($recoverable->refresh())->status->toBe(AttachmentStatus::Pending)->error_code->toBeNull()
        ->and($tooOld->refresh()->status)->toBe(AttachmentStatus::Unavailable)
        ->and($credentials->refresh()->status)->toBe(AttachmentStatus::Unavailable);
});

test('animated stickers larger than 500 KiB are kept, up to the received-sticker limit', function () {
    $bytes = largeAnimatedSticker(900_000);
    $attachment = ($this->receiveSticker)('7001', true);
    ($this->fakeMeta)(['7001' => $bytes]);

    app()->call([new DownloadInboundMedia($attachment->id), 'handle']);

    expect($attachment->refresh())
        ->status->toBe(AttachmentStatus::Available)
        ->mime_type->toBe('image/webp')
        ->size_bytes->toBe(900_000)
        ->and(Storage::disk('local')->get((string) $attachment->path))->toBe($bytes);
});

test('a sticker above the received-sticker limit is refused before or while downloading', function (bool $reported) {
    config(['whatsapp.media.types.sticker.max_bytes' => 600_000]);
    $bytes = largeAnimatedSticker(700_000);
    $attachment = ($this->receiveSticker)('7002', true);
    Http::fake([
        'graph.facebook.com/v26.0/7002' => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=7002&hash=ATsigned', 'mime_type' => 'image/webp', 'file_size' => $reported ? strlen($bytes) : null]),
        'lookaside.fbsbx.com/*' => Http::response($bytes, 200, ['Content-Type' => 'image/webp']),
    ]);

    app()->call([new DownloadInboundMedia($attachment->id), 'handle']);

    expect($attachment->refresh())
        ->status->toBe(AttachmentStatus::Unavailable)
        ->error_code->toBe('too_large')
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with(['size reported by Meta' => true, 'size only known while streaming' => false]);

test('the sticker limit is the only media limit that changed', function () {
    expect(config('whatsapp.media.types.sticker.max_bytes'))->toBe(5 * 1024 * 1024)
        ->and(config('whatsapp.media.types.sticker.mimes'))->toBe(['image/webp'])
        ->and(config('whatsapp.media.types.image.max_bytes'))->toBe(5 * 1024 * 1024)
        ->and(config('whatsapp.media.types.video.max_bytes'))->toBe(16 * 1024 * 1024)
        ->and(config('whatsapp.media.types.audio.max_bytes'))->toBe(16 * 1024 * 1024);
});

test('the conversation tells static and animated stickers apart', function () {
    $static = ($this->receiveSticker)('8001', false);
    $animated = ($this->receiveSticker)('8002', true);

    $messages = collect([$static, $animated])->map(fn (MessageAttachment $attachment): array => (new ConversationMessageResource($attachment->message->load('attachments')))->resolve(request()));

    expect($messages->pluck('type')->all())->toBe(['sticker', 'sticker'])
        ->and($messages->pluck('content.animated')->all())->toBe([false, true]);
});
