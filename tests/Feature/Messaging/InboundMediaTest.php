<?php

use App\Actions\Conversations\ProcessInboundMessages;
use App\Enums\AttachmentStatus;
use App\Enums\MessageType;
use App\Jobs\DownloadInboundMedia;
use App\Models\Connection;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Meta\MediaRejected;
use App\Services\Meta\MetaGraphClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
| Customer media: announced by the webhook, registered as pending, then
| downloaded by a job from Meta's media host with the token in the header,
| validated by its real content and stored on the private disk.
*/

const MEDIA_TOKEN = 'EAAGmediaTestToken0000000000000000000000000000000000000';
const MEDIA_URL = 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=1479537139650973&ext=1761000000&hash=ATsigned';

/** A 1x1 PNG. */
function pngBytes(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
}

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake('local');
    config(['services.meta.graph_version' => 'v26.0', 'filesystems.media_disk' => 'local']);
    $this->connection = Connection::factory()->create(['access_token' => MEDIA_TOKEN]);
    $this->receiveImage = function (string $mime = 'image/png'): Message {
        $payload = whatsappWebhook($this->connection, [[
            'from' => '5215512345678',
            'id' => 'wamid.'.fake()->unique()->numerify('########'),
            'timestamp' => (string) now()->getTimestamp(),
            'type' => 'image',
            'image' => ['id' => '1479537139650973', 'mime_type' => $mime, 'sha256' => 'abc', 'caption' => 'Mi recibo'],
        ]]);

        return app(ProcessInboundMessages::class)->handle($payload)[0]->message;
    };
});

function metaMedia(int $size = 68, string $url = MEDIA_URL): array
{
    return ['messaging_product' => 'whatsapp', 'url' => $url, 'mime_type' => 'image/png', 'sha256' => 'abc', 'file_size' => $size, 'id' => '1479537139650973'];
}

test('an image is registered as pending and its download is queued after the message is stored', function () {
    Queue::fake();

    $message = ($this->receiveImage)();
    $attachment = $message->attachments()->sole();

    expect($message->type)->toBe(MessageType::Image)
        ->and($message->body)->toBe('Mi recibo')
        ->and($attachment)
        ->status->toBe(AttachmentStatus::Pending)
        ->whatsapp_media_id->toBe('1479537139650973')
        ->path->toBeNull()
        ->mime_type->toBe('image/png');
    Queue::assertPushedOn('media', DownloadInboundMedia::class, fn (DownloadInboundMedia $job): bool => $job->attachmentId === $attachment->id);
});

test('the file is downloaded from Meta with the token in the header and stored privately', function () {
    Queue::fake();
    $attachment = ($this->receiveImage)()->attachments()->sole();
    Http::fake([
        'graph.facebook.com/v26.0/1479537139650973' => Http::response(metaMedia()),
        'lookaside.fbsbx.com/*' => Http::response(pngBytes(), 200, ['Content-Type' => 'image/png']),
    ]);
    $temporaryBefore = glob(sys_get_temp_dir().'/wa-media-*') ?: [];

    app()->call([new DownloadInboundMedia($attachment->id), 'handle']);

    $attachment->refresh();
    expect($attachment)
        ->status->toBe(AttachmentStatus::Available)
        ->mime_type->toBe('image/png')
        ->size_bytes->toBe(strlen(pngBytes()))
        ->sha256->toBe(hash('sha256', pngBytes()))
        ->disk->toBe('local')
        ->path->toMatch('#^media/\d{4}/\d{2}/[0-9a-f-]{36}$#')
        ->downloaded_at->not->toBeNull()
        ->and(Storage::disk('local')->get($attachment->path))->toBe(pngBytes())
        ->and(glob(sys_get_temp_dir().'/wa-media-*') ?: [])->toBe($temporaryBefore);

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://lookaside.fbsbx.com/')
        && $request->hasHeader('Authorization', 'Bearer '.MEDIA_TOKEN)
        && ! str_contains($request->url(), MEDIA_TOKEN));
});

test('media Meta no longer serves or that breaks the rules is marked unavailable, never stored', function (Closure $fakes, string $reason) {
    Queue::fake();
    $attachment = ($this->receiveImage)()->attachments()->sole();
    Http::fake($fakes());

    app()->call([new DownloadInboundMedia($attachment->id), 'handle']);

    expect($attachment->refresh())
        ->status->toBe(AttachmentStatus::Unavailable)
        ->error_code->toBe($reason)
        ->path->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'expired media id' => [fn () => ['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Unsupported get request.', 'code' => 100]], 400)], 'expired'],
    'declared too large' => [fn () => ['graph.facebook.com/*' => Http::response(metaMedia(size: 50 * 1024 * 1024))], 'too_large'],
    'content is not an image' => [fn () => [
        'graph.facebook.com/*' => Http::response(metaMedia()),
        'lookaside.fbsbx.com/*' => Http::response('<?php echo "not an image";', 200, ['Content-Type' => 'image/png']),
    ], 'type_not_allowed'],
    'download URL outside Meta' => [fn () => ['graph.facebook.com/*' => Http::response(metaMedia(url: 'https://attacker.example/steal'))], 'expired'],
    'token rejected' => [fn () => ['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'code' => 190]], 401)], 'credentials_rejected'],
]);

test('a download larger than allowed is stopped while streaming when Meta reports no size', function () {
    Queue::fake();
    config(['whatsapp.media.types.image.max_bytes' => 1024]);
    $attachment = ($this->receiveImage)()->attachments()->sole();
    Http::fake([
        'graph.facebook.com/v26.0/1479537139650973' => Http::response([...metaMedia(), 'file_size' => null]),
        'lookaside.fbsbx.com/*' => Http::response(pngBytes().str_repeat("\0", 4096), 200, ['Content-Type' => 'image/png']),
    ]);
    $temporaryBefore = glob(sys_get_temp_dir().'/wa-media-*') ?: [];

    app()->call([new DownloadInboundMedia($attachment->id), 'handle']);

    expect($attachment->refresh())
        ->status->toBe(AttachmentStatus::Unavailable)
        ->error_code->toBe('too_large')
        ->path->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(glob(sys_get_temp_dir().'/wa-media-*') ?: [])->toBe($temporaryBefore);
});

test('the download client stops writing as soon as the file exceeds the limit', function () {
    Http::fake(['lookaside.fbsbx.com/*' => Http::response(str_repeat('x', 4096))]);
    $destination = (string) tempnam(sys_get_temp_dir(), 'wa-cap-');

    try {
        expect(fn () => app(MetaGraphClient::class)->download(MEDIA_TOKEN, MEDIA_URL, $destination, 1024))
            ->toThrow(MediaRejected::class)
            ->and(filesize($destination))->toBeLessThanOrEqual(1024);
    } finally {
        @unlink($destination);
    }
});

test('a token rejected while downloading media marks the connection credentials invalid', function () {
    Queue::fake();
    $this->connection->forceFill(['credentials_verified_at' => now()])->save();
    $attachment = ($this->receiveImage)()->attachments()->sole();
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'code' => 190]], 401)]);

    app()->call([new DownloadInboundMedia($attachment->id), 'handle']);

    expect($this->connection->refresh())
        ->credentials_verified_at->toBeNull()
        ->credentials_error->toContain('190')
        ->credentials_error->not->toContain(MEDIA_TOKEN);
});

test('a temporary Meta failure keeps the attachment pending for a later attempt', function () {
    Queue::fake();
    $attachment = ($this->receiveImage)()->attachments()->sole();
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Temporarily unavailable', 'code' => 2]], 503)]);

    app()->call([new DownloadInboundMedia($attachment->id), 'handle']);

    expect($attachment->refresh())
        ->status->toBe(AttachmentStatus::Pending)
        ->download_attempts->toBe(1);
});

test('after every attempt failed the file is reported unavailable', function () {
    Queue::fake();
    $attachment = ($this->receiveImage)()->attachments()->sole();

    (new DownloadInboundMedia($attachment->id))->failed(new RuntimeException('worker killed'));

    expect($attachment->refresh())->status->toBe(AttachmentStatus::Unavailable)->error_code->toBe('download_failed');
});

test('an available attachment is never downloaded again', function () {
    Http::fake();
    $message = Message::factory()->create(['type' => 'image']);
    $attachment = new MessageAttachment;
    $attachment->forceFill(['message_id' => $message->id, 'whatsapp_media_id' => '1', 'disk' => 'local', 'path' => 'media/x', 'mime_type' => 'image/png'])->save();

    app()->call([new DownloadInboundMedia($attachment->id), 'handle']);

    Http::assertNothingSent();
});
