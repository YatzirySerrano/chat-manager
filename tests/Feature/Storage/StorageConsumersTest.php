<?php

use App\Enums\AttachmentDeliveryMethod;
use App\Enums\AttachmentStatus;
use App\Enums\DataTransferType;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\DataTransfer;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Models\WebChatSession;
use App\Services\Operations\HealthCheck;
use App\Services\Operations\OperationalAlerts;
use App\Services\Operations\SystemHealth;
use App\Support\Attachments\AttachmentDownloadLink;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Tests\Support\WebChatFixtures;
use Tests\Support\WebChatVisitor;

/*
| Consumers of the private storage that must work on any disk (an external
| S3-like disk has no local path) and answer a controlled 503 when the
| storage itself fails, never a 500.
*/

/**
 * A disk that behaves like a remote one: same files as a local folder, but
 * its adapter is not the local one (no local path to read from).
 */
function remoteLikeStorageDisk(string $name): void
{
    $root = storage_path('framework/testing/disks/'.$name);
    $local = new LocalFilesystemAdapter($root);
    $adapter = new class($local) implements FlysystemAdapter
    {
        public function __construct(private LocalFilesystemAdapter $inner) {}

        public function fileExists(string $path): bool
        {
            return $this->inner->fileExists($path);
        }

        public function directoryExists(string $path): bool
        {
            return $this->inner->directoryExists($path);
        }

        public function write(string $path, string $contents, Config $config): void
        {
            $this->inner->write($path, $contents, $config);
        }

        public function writeStream(string $path, $contents, Config $config): void
        {
            $this->inner->writeStream($path, $contents, $config);
        }

        public function read(string $path): string
        {
            return $this->inner->read($path);
        }

        public function readStream(string $path)
        {
            return $this->inner->readStream($path);
        }

        public function delete(string $path): void
        {
            $this->inner->delete($path);
        }

        public function deleteDirectory(string $path): void
        {
            $this->inner->deleteDirectory($path);
        }

        public function createDirectory(string $path, Config $config): void
        {
            $this->inner->createDirectory($path, $config);
        }

        public function setVisibility(string $path, string $visibility): void
        {
            $this->inner->setVisibility($path, $visibility);
        }

        public function visibility(string $path): FileAttributes
        {
            return $this->inner->visibility($path);
        }

        public function mimeType(string $path): FileAttributes
        {
            return $this->inner->mimeType($path);
        }

        public function lastModified(string $path): FileAttributes
        {
            return $this->inner->lastModified($path);
        }

        public function fileSize(string $path): FileAttributes
        {
            return $this->inner->fileSize($path);
        }

        public function listContents(string $path, bool $deep): iterable
        {
            return $this->inner->listContents($path, $deep);
        }

        public function move(string $source, string $destination, Config $config): void
        {
            $this->inner->move($source, $destination, $config);
        }

        public function copy(string $source, string $destination, Config $config): void
        {
            $this->inner->copy($source, $destination, $config);
        }
    };

    Storage::extend('remote-like', fn () => new FilesystemAdapter(new Filesystem($adapter), $adapter, ['driver' => 'remote-like']));
    config(["filesystems.disks.{$name}" => ['driver' => 'remote-like']]);
    Storage::forgetDisk($name);
}

/**
 * A disk whose every operation fails, like an external storage that does not answer.
 */
function unreachableStorageDisk(): void
{
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('exists', 'lastModified', 'path', 'response', 'readStream', 'get')->andThrow(new RuntimeException('Connection timed out'));
    Storage::extend('unreachable', fn () => $disk);
    config(['filesystems.disks.unreachable' => ['driver' => 'unreachable']]);
    Storage::forgetDisk('unreachable');
}

function servedAttachment(Message $message, string $disk, array $attributes = []): MessageAttachment
{
    $attachment = new MessageAttachment;
    $attachment->forceFill([
        'message_id' => $message->id,
        'disk' => $disk,
        'path' => 'media/2026/10/'.Str::uuid(),
        'mime_type' => 'application/pdf',
        'file_name' => 'factura.pdf',
        'size_bytes' => 9,
        'status' => AttachmentStatus::Available,
        'delivery_method' => AttachmentDeliveryMethod::Native,
        ...$attributes,
    ])->save();

    return $attachment;
}

test('a contact import kept on a remote disk is read through a temporary copy that is always removed', function () {
    remoteLikeStorageDisk('imports-remote');
    config(['imports.disk' => 'imports-remote']);
    $owner = User::factory()->superAdministrator()->create();
    $path = tempnam(sys_get_temp_dir(), 'import');
    file_put_contents($path, "Nombre,Teléfono\nAna Remota,5512345678\n");
    $before = glob(sys_get_temp_dir().'/cm-import-*') ?: [];

    $this->actingAs($owner)->post(route('contacts.import.store'), ['file' => new UploadedFile($path, 'contactos.csv', null, null, true)])->assertSessionHasNoErrors();
    $transfer = DataTransfer::query()->where('type', DataTransferType::Import)->latest('id')->firstOrFail();

    expect($transfer->disk)->toBe('imports-remote')
        ->and(Storage::disk('imports-remote')->exists($transfer->options['source_path']))->toBeTrue();

    $this->actingAs($owner)->put(route('contacts.import.update', $transfer), ['mapping' => $transfer->refresh()->options['mapping'], 'decision' => 'skip'])->assertSessionHasNoErrors();
    $this->actingAs($owner)->post(route('contacts.import.confirm', $transfer))->assertSessionHasNoErrors();

    expect(Contact::query()->where('name', 'Ana Remota')->exists())->toBeTrue()
        ->and(glob(sys_get_temp_dir().'/cm-import-*') ?: [])->toBe($before);
});

test('the widget avatar stays visible after the media disk changes', function () {
    Storage::fake('local');
    Storage::fake('media-new');
    config(['filesystems.media_disk' => 'local']);
    $admin = User::factory()->administrator()->create();
    $webChat = WebChatFixtures::connection(['allowed_origins' => ['https://tienda.test']]);
    $png = UploadedFile::fake()->image('logo.png', 32, 32);
    $path = 'widgets/avatar-'.Str::uuid().'.png';
    Storage::disk('local')->put($path, (string) file_get_contents($png->getPathname()));
    $webChat->webChatWidget->forceFill(['avatar_path' => $path])->save();

    config(['filesystems.media_disk' => 'media-new']);

    $this->actingAs($admin)
        ->get(route('connections.show', $webChat))
        ->assertInertia(fn (Assert $page) => $page
            ->where('connection.web_chat_widget.avatar_url', fn (?string $url) => is_string($url) && str_starts_with($url, 'data:image/png;base64,')));

    $frame = $this->get(route('widget.frame', ['publicKey' => $webChat->webChatWidget->public_key]), ['Referer' => 'https://tienda.test/']);
    preg_match('#<script type="application/json" id="cm-config"[^>]*>(.*?)</script>#s', (string) $frame->getContent(), $match);
    $config = json_decode($match[1] ?? 'null', true);

    expect($config['appearance']['avatar'])->toStartWith('data:image/png;base64,');
});

test('attachments near the contracted space raise an operational alert', function () {
    $health = Mockery::mock(SystemHealth::class)->makePartial();
    $health->shouldReceive('scheduler', 'webhookBacklog', 'queueBacklog', 'database')->andReturn(HealthCheck::ok('x', 'X', 'Bien'));
    $this->app->instance(SystemHealth::class, $health);
    config(['attachments.storage.warning_percent' => 80]);

    $health->shouldReceive('storage')->once()->andReturn(HealthCheck::warning('storage', 'Almacenamiento', 'Los archivos adjuntos ocupan 9 GB, el 90% del espacio contratado.', ['media_used_percent' => 90]));
    expect(collect(app(OperationalAlerts::class)->evaluate())->pluck('key')->all())->toContain('storage');

    $health->shouldReceive('storage')->once()->andReturn(HealthCheck::ok('storage', 'Almacenamiento', 'La plataforma puede guardar archivos.', ['media_used_percent' => 40]));
    expect(collect(app(OperationalAlerts::class)->evaluate())->pluck('key')->all())->not->toContain('storage');

    $health->shouldReceive('storage')->once()->andReturn(HealthCheck::warning('storage', 'Almacenamiento', 'No se pueden guardar archivos adjuntos nuevos.', ['media_problem' => 'unreachable']));
    expect(collect(app(OperationalAlerts::class)->evaluate())->pluck('key')->all())->toContain('storage');
});

test('a secure download link answers 503 when the storage does not respond', function () {
    unreachableStorageDisk();
    $conversation = Conversation::factory()->create();
    $message = Message::factory()->create(['conversation_id' => $conversation->id, 'direction' => MessageDirection::Outbound, 'type' => MessageType::Text, 'status' => 'accepted', 'body' => 'Enlace']);
    $attachment = servedAttachment($message, 'unreachable', ['delivery_method' => AttachmentDeliveryMethod::Link]);

    $this->get(app(AttachmentDownloadLink::class)->url($attachment, now()->addDay()->toImmutable()))
        ->assertStatus(503);
});

test('Meta fetching a file for Messenger gets 503 when the storage does not respond', function () {
    unreachableStorageDisk();
    $connection = Connection::factory()->messenger()->create();
    $conversation = Conversation::factory()->create(['connection_id' => $connection->id]);
    $message = Message::factory()->create(['conversation_id' => $conversation->id, 'direction' => MessageDirection::Outbound, 'type' => MessageType::Document, 'status' => 'accepted', 'body' => null]);
    $attachment = servedAttachment($message, 'unreachable');

    $this->get(URL::temporarySignedRoute('meta.media', now()->addHour(), ['attachment' => $attachment->id]))->assertStatus(503);
});

test('a web chat visitor gets 503 for a file when the storage does not respond', function () {
    Storage::fake('local');
    unreachableStorageDisk();
    $connection = WebChatFixtures::connection();
    $token = WebChatVisitor::token($this, $connection);
    WebChatVisitor::send($this, $token)->assertCreated();
    $conversation = Conversation::query()->sole();
    $session = WebChatSession::query()->sole();
    $message = Message::factory()->create(['conversation_id' => $conversation->id, 'direction' => MessageDirection::Outbound, 'type' => MessageType::Document, 'status' => 'accepted', 'body' => null]);
    $attachment = servedAttachment($message, 'unreachable');

    $this->withoutToken()
        ->get(URL::temporarySignedRoute('api.v1.widget.session.attachments.show', now()->addMinutes(10), ['attachment' => $attachment->id, 's' => $session->public_id]))
        ->assertStatus(503);
});
