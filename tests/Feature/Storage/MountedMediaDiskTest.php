<?php

use App\Actions\WebChat\UpdateWidgetSettings;
use App\Enums\AttachmentStatus;
use App\Exceptions\StorageUnavailable;
use App\Jobs\DownloadInboundMedia;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\Meta\MediaStorage;
use App\Services\Operations\Preflight;
use App\Services\Storage\MediaDisk;
use App\Services\Storage\MountHealth;
use App\Services\Storage\MountInspector;
use App\Services\Storage\SystemMountInspector;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Storage\FakeMountInspector;
use Tests\Support\WebChatFixtures;
use Tests\Support\WhatsAppFixtures;

/*
| Media disk on a NAS share mounted on the server (MEDIA_DISK=nas): files
| are only written while the mount is verified (cached bounded probe, mount
| table, identity marker), through a temporary file renamed into place and
| checked again (size and SHA-256). The share is simulated with a temporary
| directory, a marker file and a fake mount table (FakeMountInspector).
*/

const MOUNTED_DISK_ID = 'cm-media-test-0001';

const MOUNTED_DISK_TOKEN = 'EAAGmountedDiskTestToken000000000000000000000000000000000';

/** A 1x1 PNG. */
function mountedDiskPng(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
}

function mountedDiskTempFile(string $contents): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'mounted-disk-src-');
    file_put_contents($path, $contents);

    return $path;
}

/**
 * Files under the simulated share other than the identity marker.
 *
 * @return list<string>
 */
function mountedDiskFiles(string $root): array
{
    return collect(File::allFiles($root, true))
        ->map(fn (SplFileInfo $file): string => ltrim(str_replace($root, '', $file->getPathname()), '/'))
        ->reject(fn (string $path): bool => $path === '.chat-manager-storage')
        ->sort()->values()->all();
}

function mountedDiskHealthyProbe(): void
{
    app()->instance(MountHealth::class, new MountHealth(app('cache.store'), app(MountInspector::class), [PHP_BINARY, '-r', 'echo json_encode(["problem" => null]);']));
}

function mountedDiskPendingImage(Conversation $conversation): MessageAttachment
{
    $message = Message::factory()->create(['conversation_id' => $conversation->id, 'direction' => 'inbound', 'type' => 'image', 'status' => 'received', 'body' => null]);
    $attachment = new MessageAttachment;
    $attachment->forceFill(['message_id' => $message->id, 'whatsapp_media_id' => '1479537139650974', 'disk' => 'nas', 'path' => null, 'mime_type' => 'image/png', 'status' => AttachmentStatus::Pending])->save();

    return $attachment;
}

function mountedDiskServedAttachment(Conversation $conversation, string $disk, string $path): MessageAttachment
{
    $message = WhatsAppFixtures::outbound($conversation, ['type' => 'image']);
    $attachment = new MessageAttachment;
    $attachment->forceFill(['message_id' => $message->id, 'disk' => $disk, 'path' => $path, 'mime_type' => 'image/png', 'size_bytes' => strlen(mountedDiskPng())])->save();

    return $attachment;
}

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-11-11 15:00:00', 'UTC'));

    $this->root = sys_get_temp_dir().'/cm-nas-'.Str::random(10);
    mkdir($this->root, 0700, true);
    file_put_contents($this->root.'/.chat-manager-storage', MOUNTED_DISK_ID."\n");

    config([
        'filesystems.media_disk' => 'nas',
        'filesystems.disks.nas' => [
            'driver' => 'local',
            'root' => $this->root,
            'serve' => false,
            'throw' => false,
            'mount' => ['marker' => '.chat-manager-storage', 'id' => MOUNTED_DISK_ID, 'filesystem_types' => 'cifs,smb3'],
        ],
        'attachments.storage.media_disk_required' => true,
    ]);

    $this->inspector = new FakeMountInspector;
    $this->app->instance(MountInspector::class, $this->inspector);
    app(MountHealth::class)->record('nas', null);
});

afterEach(function () {
    if (is_dir($this->root)) {
        @chmod($this->root, 0700);
        foreach (File::directories($this->root) as $directory) {
            @chmod($directory, 0700);
        }
        File::deleteDirectory($this->root);
    }
});

test('a verified mount is accepted as required external storage and receives the complete file under its final name only', function (string $directory) {
    $source = mountedDiskTempFile(mountedDiskPng());

    $stored = app(MediaDisk::class)->putFile($source, $directory);

    expect(app(MediaDisk::class)->ready())->toBeTrue()
        ->and($stored['disk'])->toBe('nas')
        ->and($stored['path'])->toStartWith($directory.'/')
        ->and(file_get_contents($this->root.'/'.$stored['path']))->toBe(mountedDiskPng())
        ->and(mountedDiskFiles($this->root))->toBe([$stored['path']])
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(Storage::disk('nas')->get($stored['path']))->toBe(mountedDiskPng());

    unlink($source);
})->with([
    'conversation media' => 'media/2026/11',
    'team chat files' => 'team-chat/2026/11',
    'imported history media' => 'media/history/2026/11',
]);

test('an unverified mount refuses the file with a controlled error and writes nothing anywhere', function (Closure $breakMount, string $reason) {
    $breakMount($this);
    $source = mountedDiskTempFile(mountedDiskPng());

    expect(app(MediaDisk::class)->problem())->toBe($reason);

    try {
        app(MediaDisk::class)->putFile($source, 'media/2026/11');
        $this->fail('The file should have been refused.');
    } catch (StorageUnavailable $exception) {
        expect($exception->reason)->toBe($reason)
            ->and($exception->getMessage())->toBe(StorageUnavailable::DEFAULT_MESSAGE);
    }

    expect(mountedDiskFiles($this->root))->toBe([])
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    unlink($source);
})->with([
    'share unmounted (empty mountpoint, no marker)' => [function (object $test): void {
        unlink($test->root.'/.chat-manager-storage');
    }, 'marker_missing'],
    'another storage mounted (wrong marker id)' => [function (object $test): void {
        file_put_contents($test->root.'/.chat-manager-storage', 'other-nas');
    }, 'marker_mismatch'],
    'nothing mounted at the path' => [function (object $test): void {
        $test->inspector->type = null;
    }, 'not_mounted'],
    'a local filesystem mounted there' => [function (object $test): void {
        $test->inspector->type = 'ext4';
    }, 'wrong_filesystem'],
    'the last health probe failed' => [function (object $test): void {
        app(MountHealth::class)->record('nas', 'probe_timeout');
    }, 'probe_timeout'],
    'no identity configured' => [function (object $test): void {
        config(['filesystems.disks.nas.mount.id' => null]);
    }, 'not_configured'],
]);

test('the full probe needs a real mountpoint and leaves no probe files behind', function () {
    $health = app(MountHealth::class);

    expect($health->fullProblem('nas'))->toBeNull()
        ->and(mountedDiskFiles($this->root))->toBe([]);

    $this->inspector->devices = ['path' => 2049, 'parent' => 2049];
    expect($health->fullProblem('nas'))->toBe('not_mounted');

    $this->inspector->devices = null;
    expect($health->fullProblem('nas'))->toBe('not_mounted');
});

test('without a readable mount table the marker and the cached device check still decide', function () {
    $this->inspector->tableReadable = false;
    $this->inspector->type = null;

    expect(app(MediaDisk::class)->problem())->toBeNull();

    unlink($this->root.'/.chat-manager-storage');
    expect(app(MediaDisk::class)->problem())->toBe('marker_missing');
});

test('a hung health probe is killed at its timeout and the disk counts as unavailable', function () {
    config(['attachments.storage.mount.probe_timeout_seconds' => 1]);
    $health = new MountHealth(app('cache.store'), $this->inspector, [PHP_BINARY, '-r', 'sleep(30);']);
    $this->app->instance(MountHealth::class, $health);
    $health->forget('nas');
    $started = microtime(true);

    expect(app(MediaDisk::class)->problem())->toBe('probe_timeout')
        ->and(microtime(true) - $started)->toBeLessThan(10)
        ->and($health->lastCheck('nas')['problem'])->toBe('probe_timeout');

    $started = microtime(true);
    expect(app(MediaDisk::class)->ready())->toBeFalse()
        ->and(microtime(true) - $started)->toBeLessThan(0.5);
});

test('the probe result is read from the last line of the probe process and anything else is a failure', function (string $script, ?string $problem) {
    $health = new MountHealth(app('cache.store'), $this->inspector, [PHP_BINARY, '-r', $script]);

    expect($health->refresh('nas'))->toBe($problem);
})->with([
    'healthy' => ['echo "booting\n", json_encode(["problem" => null]);', null],
    'reported problem' => ['echo json_encode(["problem" => "marker_missing"]); exit(1);', 'marker_missing'],
    'crashed' => ['fwrite(STDERR, "fatal"); exit(255);', 'probe_failed'],
]);

test('the artisan probe runs every check in its own process and prints the result', function () {
    $exit = Artisan::call('storage:mount-health', ['disk' => 'nas', '--probe' => true]);

    expect($exit)->toBe(0)
        ->and(json_decode(trim(Artisan::output()), true))->toBe(['problem' => null]);

    unlink($this->root.'/.chat-manager-storage');
    Artisan::call('storage:mount-health', ['disk' => 'nas', '--probe' => true]);

    expect(json_decode(trim(Artisan::output()), true))->toBe(['problem' => 'marker_missing']);
});

test('writes are allowed again once the share is back and its probe passes', function () {
    $marker = $this->root.'/.chat-manager-storage';
    unlink($marker);
    app(MountHealth::class)->record('nas', 'marker_missing');
    $source = mountedDiskTempFile(mountedDiskPng());

    expect(fn () => app(MediaDisk::class)->putFile($source, 'media/2026/11'))->toThrow(StorageUnavailable::class);

    file_put_contents($marker, MOUNTED_DISK_ID);
    mountedDiskHealthyProbe();
    Artisan::call('storage:mount-health');

    $stored = app(MediaDisk::class)->putFile($source, 'media/2026/11');

    expect(is_file($this->root.'/'.$stored['path']))->toBeTrue();

    unlink($source);
});

test('a share that refuses writes fails the upload and leaves no partial file', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('Permissions are not enforced for root.');
    }

    mkdir($this->root.'/media', 0700);
    chmod($this->root.'/media', 0500);
    $source = mountedDiskTempFile(mountedDiskPng());

    expect(fn () => app(MediaDisk::class)->putFile($source, 'media/2026/11'))
        ->toThrow(fn (StorageUnavailable $exception) => expect($exception->reason)->toBe('write_failed'));

    chmod($this->root.'/media', 0700);
    expect(mountedDiskFiles($this->root))->toBe([]);

    unlink($source);
});

test('a source that cannot be read is refused before touching the share', function () {
    expect(fn () => app(MediaDisk::class)->putFile($this->root.'/missing-source', 'media/2026/11'))
        ->toThrow(fn (StorageUnavailable $exception) => expect($exception->reason)->toBe('read_failed'));

    expect(mountedDiskFiles($this->root))->toBe([]);
});

test('an existing file is never replaced by a new upload with the same name', function () {
    mkdir($this->root.'/web-chat/avatars', 0700, true);
    file_put_contents($this->root.'/web-chat/avatars/7-taken.png', 'original');
    $source = mountedDiskTempFile(mountedDiskPng());

    expect(fn () => app(MediaDisk::class)->putFile($source, 'web-chat/avatars', '7-taken.png'))->toThrow(StorageUnavailable::class)
        ->and(file_get_contents($this->root.'/web-chat/avatars/7-taken.png'))->toBe('original')
        ->and(mountedDiskFiles($this->root))->toBe(['web-chat/avatars/7-taken.png']);

    unlink($source);
});

test('many uploads to the same directory get distinct names and a large file arrives intact', function () {
    $small = mountedDiskTempFile(mountedDiskPng());
    $large = mountedDiskTempFile(random_bytes(3 * 1024 * 1024 + 17));
    $paths = [];

    foreach (range(1, 25) as $ignored) {
        $paths[] = app(MediaDisk::class)->putFile($small, 'media/2026/11')['path'];
    }

    $big = app(MediaDisk::class)->putFile($large, 'media/2026/11')['path'];

    expect(array_unique($paths))->toHaveCount(25)
        ->and(hash_file('sha256', $this->root.'/'.$big))->toBe(hash_file('sha256', $large))
        ->and(mountedDiskFiles($this->root))->toHaveCount(26)
        ->and(collect(mountedDiskFiles($this->root))->filter(fn (string $path): bool => str_ends_with($path, '.part')))->toBeEmpty();

    unlink($small);
    unlink($large);
});

test('customer media is held as storage_unavailable while the share is down and recovered once it returns', function () {
    $department = Department::factory()->create();
    $agent = User::factory()->agent()->inDepartments($department)->create();
    $connection = Connection::factory()->verified()->create(['phone_number_id' => '106540352242923', 'access_token' => MOUNTED_DISK_TOKEN]);
    $conversation = Conversation::factory()->inDepartment($department)->assignedTo($agent)->create(['connection_id' => $connection->id, 'last_inbound_at' => now()->subHour()]);
    config(['services.meta.graph_version' => 'v26.0', 'whatsapp.transport' => 'meta', 'whatsapp.media.download_tries' => 1]);
    Http::fake([
        'graph.facebook.com/v26.0/1479537139650974' => fn () => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=2', 'mime_type' => 'image/png', 'file_size' => strlen(mountedDiskPng()), 'id' => '1479537139650974']),
        'lookaside.fbsbx.com/*' => fn () => Http::response(mountedDiskPng(), 200, ['Content-Type' => 'image/png']),
    ]);
    $attachment = mountedDiskPendingImage($conversation);
    mountedDiskHealthyProbe();
    unlink($this->root.'/.chat-manager-storage');

    app()->call([new DownloadInboundMedia($attachment->id), 'handle']);

    expect($attachment->refresh())->status->toBe(AttachmentStatus::Unavailable)->error_code->toBe(StorageUnavailable::CODE)
        ->and(mountedDiskFiles($this->root))->toBe([])
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    Queue::fake();
    $this->travel(2)->hours();
    Artisan::call('attachments:recover-stale');
    Queue::assertNothingPushed();

    file_put_contents($this->root.'/.chat-manager-storage', MOUNTED_DISK_ID);
    Artisan::call('attachments:recover-stale');
    Queue::assertPushed(DownloadInboundMedia::class, fn (DownloadInboundMedia $job): bool => $job->attachmentId === $attachment->id);

    app()->call([new DownloadInboundMedia($attachment->id), 'handle']);

    expect($attachment->refresh())->status->toBe(AttachmentStatus::Available)->disk->toBe('nas')
        ->and(file_get_contents($this->root.'/'.$attachment->path))->toBe(mountedDiskPng())
        ->and($attachment->sha256)->toBe(hash('sha256', mountedDiskPng()));
});

test('rows saved on local, s3 and the mounted share are each served from their own disk', function () {
    $department = Department::factory()->create();
    $agent = User::factory()->agent()->inDepartments($department)->create();
    $conversation = Conversation::factory()->inDepartment($department)->assignedTo($agent)->create();
    Storage::disk('local')->put('media/2026/09/old-local', mountedDiskPng());
    Storage::fake('s3')->put('media/2026/10/old-s3', mountedDiskPng());
    $source = mountedDiskTempFile(mountedDiskPng());
    $new = app(MediaStorage::class)->store($source, 'image');
    unlink($source);

    foreach ([['local', 'media/2026/09/old-local'], ['s3', 'media/2026/10/old-s3'], ['nas', $new['path']]] as [$disk, $path]) {
        $attachment = mountedDiskServedAttachment($conversation, $disk, $path);

        $response = $this->actingAs($agent)
            ->get(route('conversations.messages.attachments.show', [$conversation, $attachment->message_id, $attachment]))
            ->assertOk();

        expect($response->streamedContent() ?: file_get_contents($response->baseResponse->getFile()->getPathname()))->toBe(mountedDiskPng());
    }

    expect($new['disk'])->toBe('nas');
});

test('the widget avatar records its disk and keeps resolving from it after MEDIA_DISK changes', function () {
    $admin = User::factory()->administrator()->create();
    $webChat = WebChatFixtures::connection();
    $widget = $webChat->webChatWidget;
    $settings = ['title' => $widget->title];

    app(UpdateWidgetSettings::class)->handle($widget, ['settings' => $settings, 'avatar' => UploadedFile::fake()->image('logo.png', 16, 16), 'remove_avatar' => false], $admin);
    $first = (string) $widget->refresh()->avatar_path;
    [$disk, $path] = UpdateWidgetSettings::avatarLocation($first);

    expect($first)->toStartWith('nas:web-chat/avatars/')
        ->and($disk)->toBe('nas')
        ->and(is_file($this->root.'/'.$path))->toBeTrue();

    config(['filesystems.media_disk' => 'local', 'attachments.storage.media_disk_required' => false]);

    expect(UpdateWidgetSettings::avatarBytes($first))->toBe(file_get_contents($this->root.'/'.$path));

    app(UpdateWidgetSettings::class)->handle($widget, ['settings' => $settings, 'avatar' => UploadedFile::fake()->image('logo.png', 16, 16), 'remove_avatar' => false], $admin);
    $second = (string) $widget->refresh()->avatar_path;

    expect($second)->not->toContain(':')
        ->and(Storage::disk('local')->exists($second))->toBeTrue()
        ->and(is_file($this->root.'/'.$path))->toBeFalse();
});

test('the preflight verifies the mount through the probe process', function (?string $probeProblem, string $status) {
    $script = 'echo json_encode(["problem" => '.var_export($probeProblem, true).']);';
    $this->app->instance(MountHealth::class, new MountHealth(app('cache.store'), $this->inspector, [PHP_BINARY, '-r', $script]));
    config(['app.env' => 'production']);

    $check = app(Preflight::class)->mediaStorage();

    expect($check->status->value)->toBe($status)
        ->and($check->details['mount'] ?? null)->toBeTrue();
})->with([
    'probe passes' => [null, 'ok'],
    'probe finds no mountpoint' => ['not_mounted', 'fail'],
]);

test('the preflight warns when an exports queue job may outlast the exports worker timeout', function () {
    config(['operations.preflight.exports_worker_timeout' => 660, 'exports.job_timeout' => 700]);
    $check = app(Preflight::class)->exportsWorkerTimeout();

    expect($check->status->value)->toBe('warning')
        ->and($check->summary)->toContain('EXPORTS_JOB_TIMEOUT');

    // History import jobs never run longer than 1800 s.
    config(['operations.preflight.exports_worker_timeout' => 1900, 'exports.job_timeout' => 600]);

    expect(app(Preflight::class)->exportsWorkerTimeout()->status->value)->toBe('ok');
});

test('the real mount table is read for the topmost mount at the exact path', function () {
    $table = implode("\n", [
        '25 1 8:1 / / rw,relatime shared:1 - ext4 /dev/sda1 rw',
        '40 25 0:40 / /mnt/chat\\040media rw,relatime shared:20 - autofs systemd-1 rw,fd=50',
        '41 40 0:41 / /mnt/chat\\040media rw,relatime shared:21 - cifs //nas/share rw,vers=3.1.1',
        '42 25 0:42 / /mnt/other rw,relatime - nfs4 server:/x rw',
    ]);

    expect(SystemMountInspector::typeFromMountTable($table, '/mnt/chat media/'))->toBe('cifs')
        ->and(SystemMountInspector::typeFromMountTable($table, '/mnt/chat'))->toBeNull()
        ->and(SystemMountInspector::typeFromMountTable($table, '/mnt/other'))->toBe('nfs4');
});

test('local and s3 media disks keep their previous readiness rules', function () {
    config(['filesystems.media_disk' => 'local', 'attachments.storage.media_disk_required' => true]);
    expect(app(MediaDisk::class)->problem())->toBe('local_not_allowed')
        ->and(app(MediaDisk::class)->isMount())->toBeFalse();

    config(['attachments.storage.media_disk_required' => false]);
    expect(app(MediaDisk::class)->problem())->toBeNull()
        ->and(app(MediaDisk::class)->isExternal())->toBeFalse();
});

test('S3 HTTP timeouts and checksum options are only added when their variables are set', function () {
    $s3 = fn (): array => (require config_path('filesystems.php'))['disks']['s3'];

    expect($s3())->not->toHaveKeys(['http', 'request_checksum_calculation', 'response_checksum_validation']);

    putenv('AWS_HTTP_CONNECT_TIMEOUT=5');
    putenv('AWS_HTTP_TIMEOUT=120');
    putenv('AWS_REQUEST_CHECKSUM_CALCULATION=when_required');

    try {
        expect($s3())
            ->http->toBe(['connect_timeout' => 5.0, 'timeout' => 120.0])
            ->request_checksum_calculation->toBe('when_required')
            ->not->toHaveKey('response_checksum_validation');
    } finally {
        putenv('AWS_HTTP_CONNECT_TIMEOUT');
        putenv('AWS_HTTP_TIMEOUT');
        putenv('AWS_REQUEST_CHECKSUM_CALCULATION');
    }
});
