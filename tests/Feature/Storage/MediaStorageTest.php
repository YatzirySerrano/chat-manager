<?php

use App\Actions\WebChat\UpdateWidgetSettings;
use App\Enums\AttachmentStatus;
use App\Enums\DataScope;
use App\Enums\MessageStatus;
use App\Enums\Permission;
use App\Exceptions\StorageUnavailable;
use App\Jobs\DeliverOutboundMessage;
use App\Jobs\DownloadInboundMedia;
use App\Models\Connection;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\Meta\MediaStorage;
use App\Services\Operations\Preflight;
use App\Services\Operations\SystemHealth;
use App\Support\Attachments\ChannelAttachmentPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Storage\FailingDisk;
use Tests\Support\WhatsAppFixtures;

/*
| Persistent media storage (MediaDisk): verified writes, an explicit refusal
| when the external disk is not ready or required, the outbound copy written
| outside the conversation lock and removed when nothing references it,
| bounded retries of inbound downloads and stale recovery, usage against the
| quota, and files still served from the disk each row was saved on.
*/

const STORAGE_TOKEN = 'EAAGstorageTestToken00000000000000000000000000000000000';

/** A 1x1 PNG. */
function storagePng(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
}

/** Points MEDIA_DISK at a complete S3-compatible configuration (no real bucket: the disk is faked). */
function useExternalMediaDisk(array $overrides = []): void
{
    config([
        'filesystems.media_disk' => 's3',
        'filesystems.disks.s3' => [
            'driver' => 's3',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'region' => 'us-east-1',
            'bucket' => 'chat-manager-test',
            'visibility' => 'private',
            'throw' => false,
            ...$overrides,
        ],
    ]);
}

function storageTempPng(): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'storage-test-');
    file_put_contents($path, storagePng());

    return $path;
}

function storageReply(object $test, ?string $reference = null): TestResponse
{
    return $test->actingAs($test->agent)->post(route('conversations.messages.store', $test->conversation), [
        'client_reference' => $reference ?? (string) Str::uuid(),
        'attachment' => UploadedFile::fake()->createWithContent('foto.png', storagePng()),
    ], ['Accept' => 'application/json']);
}

function pendingInboundImage(Conversation $conversation, array $attributes = []): MessageAttachment
{
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'type' => 'image',
        'status' => 'received',
        'body' => null,
    ]);

    $attachment = new MessageAttachment;
    $attachment->forceFill([
        'message_id' => $message->id,
        'whatsapp_media_id' => '1479537139650973',
        'disk' => (string) config('filesystems.media_disk'),
        'path' => null,
        'mime_type' => 'image/png',
        'status' => AttachmentStatus::Pending,
        ...$attributes,
    ])->save();

    return $attachment;
}

function fakeMetaImage(): void
{
    Http::fake([
        // Closures: a fresh response (and body stream) for every attempt.
        'graph.facebook.com/v26.0/1479537139650973' => fn () => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=1', 'mime_type' => 'image/png', 'file_size' => strlen(storagePng()), 'id' => '1479537139650973']),
        'lookaside.fbsbx.com/*' => fn () => Http::response(storagePng(), 200, ['Content-Type' => 'image/png']),
    ]);
}

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake('local');
    config(['filesystems.media_disk' => 'local', 'services.meta.graph_version' => 'v26.0', 'whatsapp.transport' => 'meta']);
    $this->travelTo(CarbonImmutable::parse('2026-10-20 15:00:00', 'UTC'));

    $this->department = Department::factory()->create();
    $this->agent = User::factory()->agent()->inDepartments($this->department)->create();
    $this->connection = Connection::factory()->verified()->create(['phone_number_id' => '106540352242922', 'access_token' => STORAGE_TOKEN]);
    $this->conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create([
        'connection_id' => $this->connection->id,
        'last_inbound_at' => now()->subHour(),
    ]);
});

test('a write the disk does not confirm raises StorageUnavailable and keeps nothing', function (string $failure) {
    useExternalMediaDisk();
    FailingDisk::install('s3', $failure);
    $path = storageTempPng();

    expect(fn () => app(MediaStorage::class)->store($path, 'image'))
        ->toThrow(StorageUnavailable::class)
        ->and(Storage::disk('s3')->allFiles())->toBe([])
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    unlink($path);
})->with(['write refused' => 'write', 'size mismatch' => 'verify']);

test('an incomplete external disk refuses files instead of using the server disk', function () {
    useExternalMediaDisk(['bucket' => null]);
    Storage::fake('s3');
    $path = storageTempPng();

    try {
        app(MediaStorage::class)->store($path, 'image');
        $this->fail('The file should have been refused.');
    } catch (StorageUnavailable $exception) {
        expect($exception->reason)->toBe('not_configured')
            ->and($exception->getMessage())->toContain('almacenamiento de archivos no está disponible');
    }

    expect(Storage::disk('local')->allFiles())->toBe([])
        ->and(Storage::disk('s3')->allFiles())->toBe([]);

    unlink($path);
});

test('when external storage is required a reply with a file is refused with a clear message and nothing is stored', function () {
    Queue::fake();
    config(['attachments.storage.media_disk_required' => true]);

    storageReply($this)
        ->assertStatus(503)
        ->assertJsonPath('code', 'storage_unavailable')
        ->assertJsonPath('message', StorageUnavailable::DEFAULT_MESSAGE);

    expect(Message::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
    Queue::assertNothingPushed();
});

test('a reply file is written to the external disk outside the conversation transaction', function () {
    Queue::fake();
    useExternalMediaDisk();
    $disk = FailingDisk::install('s3');
    $levelOutside = DB::transactionLevel();

    storageReply($this)->assertStatus(202);

    $attachment = MessageAttachment::query()->sole();
    expect($disk->writeTransactionLevels)->toBe([$levelOutside])
        ->and($attachment)->disk->toBe('s3')->status->toBe(AttachmentStatus::Available)
        ->and(Storage::disk('s3')->get($attachment->path))->toBe(storagePng())
        ->and(Storage::disk('local')->allFiles())->toBe([]);
    Queue::assertPushed(DeliverOutboundMessage::class);
});

test('a viewer without the reply permission gets 403 and their file is never inspected nor written', function () {
    Queue::fake();
    useExternalMediaDisk();
    $disk = FailingDisk::install('s3');
    $viewer = conversationUser([Permission::ViewConversations], DataScope::Departments, [$this->department]);

    $this->actingAs($viewer)->post(route('conversations.messages.store', $this->conversation), [
        'client_reference' => (string) Str::uuid(),
        'attachment' => UploadedFile::fake()->createWithContent('foto.png', storagePng()),
    ], ['Accept' => 'application/json'])->assertForbidden();

    expect($disk->writeTransactionLevels)->toBe([])
        ->and(Storage::disk('s3')->allFiles())->toBe([])
        ->and(Message::query()->count())->toBe(0);
});

test('a refused reply removes the copy it had stored and a repeated reference keeps a single copy', function () {
    Queue::fake();
    useExternalMediaDisk();
    Storage::fake('s3');
    $this->conversation->forceFill(['last_inbound_at' => now()->subDays(2)])->save();

    storageReply($this)->assertStatus(409);

    expect(Storage::disk('s3')->allFiles())->toBe([])
        ->and(Message::query()->count())->toBe(0);

    $this->conversation->forceFill(['last_inbound_at' => now()->subHour()])->save();
    $reference = (string) Str::uuid();

    storageReply($this, $reference)->assertStatus(202);
    storageReply($this, $reference)->assertStatus(202);

    expect(Message::query()->count())->toBe(1)
        ->and(Storage::disk('s3')->allFiles())->toHaveCount(1)
        ->and(MessageAttachment::query()->sole()->path)->toBe(Storage::disk('s3')->allFiles()[0]);
});

test('a write the external disk does not confirm refuses the reply and records nothing', function () {
    Queue::fake();
    useExternalMediaDisk();
    FailingDisk::install('s3', 'write');

    storageReply($this)->assertStatus(503)->assertJsonPath('code', 'storage_unavailable');

    expect(Message::query()->count())->toBe(0)
        ->and(MessageAttachment::query()->count())->toBe(0)
        ->and(Storage::disk('s3')->allFiles())->toBe([]);
});

test('an upload id Meta no longer accepts is uploaded once more and the same message is sent', function () {
    ContactIdentity::factory()->create(['contact_id' => $this->conversation->contact_id, 'external_id' => '5215511112222']);
    Storage::disk('local')->put('media/2026/10/receipt', storagePng());
    Http::fake([
        'graph.facebook.com/v26.0/106540352242922/media' => Http::response(['id' => 'fresh-media-id']),
        'graph.facebook.com/v26.0/106540352242922/messages' => Http::sequence()
            ->push(['error' => ['message' => '(#131053) Media upload error', 'code' => 131053]], 400)
            ->push(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.reuploaded']]]),
    ]);
    $message = WhatsAppFixtures::outbound($this->conversation, ['type' => 'image', 'body' => null]);
    $attachment = new MessageAttachment;
    $attachment->forceFill(['message_id' => $message->id, 'disk' => 'local', 'path' => 'media/2026/10/receipt', 'mime_type' => 'image/png', 'size_bytes' => 68, 'whatsapp_media_id' => 'old-media-id'])->save();

    app()->call([new DeliverOutboundMessage($message->id), 'handle']);

    $sent = Http::recorded()->map(fn (array $pair): Request => $pair[0]);
    expect($sent->filter(fn (Request $request): bool => str_ends_with($request->url(), '/media')))->toHaveCount(1)
        ->and($sent->filter(fn (Request $request): bool => str_ends_with($request->url(), '/messages'))->map(fn (Request $request) => [$request['type'], $request['image']['id']])->values()->all())
        ->toBe([['image', 'old-media-id'], ['image', 'fresh-media-id']])
        ->and($attachment->refresh()->whatsapp_media_id)->toBe('fresh-media-id')
        ->and($message->refresh()->status)->toBe(MessageStatus::Accepted);
});

test('a storage read error while sending is retried instead of refusing the message', function () {
    config(['whatsapp.delivery.max_attempts' => 2]);
    ContactIdentity::factory()->create(['contact_id' => $this->conversation->contact_id, 'external_id' => '5215511112222']);
    FailingDisk::install('local', 'read');
    Http::fake();
    $message = WhatsAppFixtures::outbound($this->conversation, ['type' => 'image', 'body' => null]);
    $attachment = new MessageAttachment;
    $attachment->forceFill(['message_id' => $message->id, 'disk' => 'local', 'path' => 'media/2026/10/receipt', 'mime_type' => 'image/png', 'size_bytes' => 68])->save();

    app()->call([new DeliverOutboundMessage($message->id), 'handle']);

    expect($message->refresh())
        ->status->toBe(MessageStatus::Queued)
        ->delivery_attempts->toBe(1);
    Http::assertNothingSent();
});

test('an inbound file the disk cannot keep is retried within its attempts and then marked storage_unavailable', function () {
    useExternalMediaDisk();
    FailingDisk::install('s3', 'write');
    fakeMetaImage();
    config(['whatsapp.media.download_tries' => 2]);
    $attachment = pendingInboundImage($this->conversation);

    app()->call([new DownloadInboundMedia($attachment->id), 'handle']);

    expect($attachment->refresh())->status->toBe(AttachmentStatus::Pending)->download_attempts->toBe(1);

    config(['whatsapp.media.download_tries' => 1]);
    app()->call([new DownloadInboundMedia($attachment->id), 'handle']);

    expect($attachment->refresh())
        ->status->toBe(AttachmentStatus::Unavailable)
        ->error_code->toBe('storage_unavailable')
        ->path->toBeNull()
        ->and(Storage::disk('s3')->allFiles())->toBe([]);
});

test('an inbound file is not downloaded while the external disk is not ready', function () {
    useExternalMediaDisk(['bucket' => null]);
    Http::fake();
    config(['whatsapp.media.download_tries' => 1]);
    $attachment = pendingInboundImage($this->conversation);

    app()->call([new DownloadInboundMedia($attachment->id), 'handle']);

    expect($attachment->refresh())->status->toBe(AttachmentStatus::Unavailable)->error_code->toBe('storage_unavailable');
    Http::assertNothingSent();
});

test('stale customer media is retried, expired or given up, bounded and only once per stale period', function () {
    Queue::fake();
    $tooOld = pendingInboundImage($this->conversation, ['created_at' => now()->subDays(8), 'updated_at' => now()->subDays(8)]);
    $exhausted = pendingInboundImage($this->conversation, ['download_attempts' => 12, 'updated_at' => now()->subHours(2)]);
    $stale = pendingInboundImage($this->conversation, ['download_attempts' => 4, 'updated_at' => now()->subHours(2)]);
    $staleToo = pendingInboundImage($this->conversation, ['download_attempts' => 1, 'updated_at' => now()->subHours(3)]);
    $fresh = pendingInboundImage($this->conversation, ['updated_at' => now()->subMinutes(5)]);
    $storageFailed = pendingInboundImage($this->conversation, ['status' => AttachmentStatus::Unavailable, 'error_code' => 'storage_unavailable', 'download_attempts' => 4, 'updated_at' => now()->subHours(2)]);

    Artisan::call('attachments:recover-stale', ['--limit' => 2]);

    expect($tooOld->refresh())->status->toBe(AttachmentStatus::Unavailable)->error_code->toBe('expired')
        ->and($exhausted->refresh())->status->toBe(AttachmentStatus::Unavailable)->error_code->toBe('download_failed')
        ->and($fresh->refresh()->status)->toBe(AttachmentStatus::Pending)
        ->and($storageFailed->refresh())->status->toBe(AttachmentStatus::Pending)->error_code->toBeNull();
    Queue::assertPushed(DownloadInboundMedia::class, 3);
    Queue::assertPushed(DownloadInboundMedia::class, fn (DownloadInboundMedia $job): bool => $job->attachmentId === $stale->id);
    Queue::assertPushed(DownloadInboundMedia::class, fn (DownloadInboundMedia $job): bool => $job->attachmentId === $staleToo->id);
    Queue::assertNotPushed(DownloadInboundMedia::class, fn (DownloadInboundMedia $job): bool => $job->attachmentId === $fresh->id);

    Artisan::call('attachments:recover-stale');

    Queue::assertPushed(DownloadInboundMedia::class, 3);
});

test('the system health warns when the recorded media usage reaches the quota warning', function (int $size, string $status) {
    config(['attachments.storage.quota_bytes' => 1000, 'attachments.storage.warning_percent' => 80]);
    $message = WhatsAppFixtures::outbound($this->conversation, ['type' => 'image']);
    $attachment = new MessageAttachment;
    $attachment->forceFill(['message_id' => $message->id, 'disk' => 'local', 'path' => 'media/x', 'mime_type' => 'image/png', 'size_bytes' => $size])->save();

    $check = app(SystemHealth::class)->storage();

    expect($check->status->value)->toBe($status)
        ->and($check->details['media_used_bytes'])->toBe($size);
})->with([
    'below the warning' => [500, 'ok'],
    'at 90 %' => [900, 'warning'],
]);

test('the preflight probes the external disk with a write and delete', function (array $failing, string $status) {
    useExternalMediaDisk();
    FailingDisk::install('s3', ...$failing);

    $check = app(Preflight::class)->mediaStorage();

    expect($check->status->value)->toBe($status)
        ->and(Storage::disk('s3')->allFiles())->toBe([]);
})->with([
    'bucket answers' => [[], 'ok'],
    'bucket refuses writes' => [['write'], 'warning'],
]);

test('files saved on the server disk are still served after MEDIA_DISK switches, only to who can see the conversation', function () {
    Storage::disk('local')->put('media/2026/10/old', storagePng());
    $message = WhatsAppFixtures::outbound($this->conversation, ['type' => 'image']);
    $attachment = new MessageAttachment;
    $attachment->forceFill(['message_id' => $message->id, 'disk' => 'local', 'path' => 'media/2026/10/old', 'mime_type' => 'image/png', 'size_bytes' => 68])->save();
    useExternalMediaDisk();
    Storage::fake('s3');
    $url = route('conversations.messages.attachments.show', [$this->conversation, $message, $attachment]);

    $response = $this->actingAs($this->agent)->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->streamedContent() ?: file_get_contents($response->baseResponse->getFile()->getPathname()))->toBe(storagePng());

    $outsider = User::factory()->agent()->inDepartments(Department::factory()->create())->create();
    $this->actingAs($outsider)->get($url)->assertNotFound();
});

test('a file on the external disk is streamed through the application with the same headers', function () {
    useExternalMediaDisk();
    Storage::fake('s3')->put('media/2026/10/new', storagePng());
    $message = WhatsAppFixtures::outbound($this->conversation, ['type' => 'image']);
    $attachment = new MessageAttachment;
    $attachment->forceFill(['message_id' => $message->id, 'disk' => 's3', 'path' => 'media/2026/10/new', 'mime_type' => 'image/png', 'size_bytes' => 68])->save();

    $response = $this->actingAs($this->agent)
        ->get(route('conversations.messages.attachments.show', [$this->conversation, $message, $attachment]))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Type', 'image/png');

    expect($response->streamedContent())->toBe(storagePng());
});

test('a widget avatar saved before MEDIA_DISK switched is still found on the server disk', function () {
    Storage::disk('local')->put('web-chat/avatars/1-old.png', storagePng());
    useExternalMediaDisk();
    Storage::fake('s3');

    expect(UpdateWidgetSettings::avatarDisk('web-chat/avatars/1-old.png'))->toBe('local')
        ->and(UpdateWidgetSettings::avatarBytes('web-chat/avatars/1-old.png'))->toBe(storagePng());
});

test('the composer receives per channel attachment categories and the storage state', function () {
    $whatsapp = app(ChannelAttachmentPolicy::class)->describeAll()['whatsapp'];
    $categories = collect($whatsapp['categories'])->keyBy('key');

    expect($categories->keys()->all())->toBe(['image', 'video', 'document', 'audio'])
        ->and($categories['image'])->mimes->toBe(['image/jpeg', 'image/png'])->max_bytes->toBe(5_000_000)->delivery->toBe('native')
        ->and($categories['document'])->kinds->toBe(['document', 'archive'])->delivery->toBe('mixed')
        ->and($categories['audio']['accept'])->toContain('audio/ogg')
        ->and(collect($whatsapp['kinds'])->firstWhere('key', 'archive'))->category->toBe('document')->delivery->toBe('link')
        ->and(collect($whatsapp['kinds'])->firstWhere('key', 'document')['mimes'])->not->toContain('application/zip')
        ->and($whatsapp['storage'])->toBe(['ready' => true, 'message' => null]);

    config(['attachments.storage.media_disk_required' => true]);

    expect(app(ChannelAttachmentPolicy::class)->describeAll()['whatsapp']['storage']['ready'])->toBeFalse();
});
