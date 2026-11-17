<?php

use App\Enums\AuditAction;
use App\Enums\DataScope;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Enums\TeamChat\TeamAttachmentKind;
use App\Enums\VideoExportStatus;
use App\Http\Resources\ConversationMessageResource;
use App\Jobs\SaveVideoToNas;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Role;
use App\Models\TeamChat\TeamAttachment;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Models\VideoExport;
use App\Services\PlatformSettings;
use App\Services\Storage\MediaDisk;
use App\Services\Storage\MountHealth;
use App\Services\VideoExports\VideoFolders;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| «Guardar video»: a folder browser over the NAS (the root of saved videos
| and its sub folders, never above it) and the copy, never the move, of a
| video of a conversation or of the internal chat into the chosen folder
| under the typed name. Only with videos.save-to-nas, only for people who
| can see the chat, and only while the NAS is available.
*/

beforeEach(function () {
    Storage::fake('local');
    config([
        'filesystems.media_disk' => 'local',
        'filesystems.disks.videos' => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/videos'), 'visibility' => 'private'],
        'attachments.storage.video_exports_disk' => 'videos',
    ]);
    Storage::fake('videos');

    // The layout the team builds by hand.
    Storage::disk('videos')->makeDirectory('VIDEOS/ORIZABA/11-Noviembre 2026/Solicitud/03-11-2026');
    Storage::disk('videos')->makeDirectory('VIDEOS/CORDOBA');
    Storage::disk('videos')->makeDirectory('Fuera de la raiz');
});

function savedVideoAttachment(Conversation $conversation): MessageAttachment
{
    $message = Message::factory()->create(['conversation_id' => $conversation->id, 'direction' => 'inbound', 'type' => 'video', 'status' => 'received', 'body' => null]);
    Storage::disk('local')->put('WhatsApp/Ulises/2026-11 Noviembre/video.mp4', 'video-bytes');
    $attachment = new MessageAttachment;
    $attachment->forceFill(['message_id' => $message->id, 'disk' => 'local', 'path' => 'WhatsApp/Ulises/2026-11 Noviembre/video.mp4', 'mime_type' => 'video/mp4', 'file_name' => null, 'size_bytes' => 11])->save();

    return $attachment;
}

function saveVideoUrl(Conversation $conversation, MessageAttachment $attachment): string
{
    return route('conversations.messages.attachments.save-video', ['conversation' => $conversation->id, 'message' => $attachment->message_id, 'attachment' => $attachment->id]);
}

function videoExportRow(MessageAttachment $attachment, array $attributes = []): VideoExport
{
    $export = new VideoExport;
    $export->forceFill(['message_attachment_id' => $attachment->id, 'folder' => 'ORIZABA/11-Noviembre 2026/Solicitud/03-11-2026', 'file_name' => 'ORIZABA SOLICITUD 03-11-2026 ULISES ABRAHAM ARZABA', 'disk' => 'videos', ...$attributes])->save();

    return $export;
}

function runVideoExport(VideoExport $export, ?SaveVideoToNas $job = null): void
{
    ($job ?? new SaveVideoToNas($export->id))->handle(app(MediaDisk::class), app(VideoFolders::class));
}

/* ---------------------------------------------------------- folder browser */

test('the browser lists the folders of the root and enters them, never above it', function () {
    $admin = User::factory()->superAdministrator()->create();
    Storage::disk('videos')->put('VIDEOS/nota.txt', 'not a folder');
    Storage::disk('videos')->makeDirectory('VIDEOS/.oculta');

    $this->actingAs($admin)->getJson(route('video-exports.folders.index', ['path' => '']))
        ->assertOk()
        ->assertJsonPath('root', 'VIDEOS')
        ->assertJsonPath('path', '')
        ->assertJsonPath('folders', ['CORDOBA', 'ORIZABA']);

    $this->actingAs($admin)->getJson(route('video-exports.folders.index', ['path' => 'ORIZABA/11-Noviembre 2026']))
        ->assertOk()
        ->assertJsonPath('segments', ['ORIZABA', '11-Noviembre 2026'])
        ->assertJsonPath('folders', ['Solicitud']);

    foreach (['..', 'ORIZABA/../..', '../Fuera de la raiz', '/etc', 'C:/Windows', 'ORIZABA/./x', 'a:b', '.oculta'] as $escape) {
        $this->actingAs($admin)->getJson(route('video-exports.folders.index', ['path' => $escape]))
            ->assertJsonValidationErrors('path');
    }

    $this->actingAs($admin)->getJson(route('video-exports.folders.index', ['path' => 'NO EXISTE']))->assertJsonValidationErrors('folder');
});

test('a symbolic link cannot lead out of the root', function () {
    $root = Storage::disk('videos')->path('VIDEOS');
    $outside = Storage::disk('videos')->path('Fuera de la raiz');

    if (! @symlink($outside, $root.'/ATAJO')) {
        $this->markTestSkipped('This system cannot create symbolic links.');
    }

    $admin = User::factory()->superAdministrator()->create();

    $this->actingAs($admin)->getJson(route('video-exports.folders.index', ['path' => '']))->assertJsonPath('folders', ['CORDOBA', 'ORIZABA']);
    $this->actingAs($admin)->getJson(route('video-exports.folders.index', ['path' => 'ATAJO']))->assertJsonValidationErrors('folder');
});

test('folders are created inside the current folder with an SMB-safe name', function () {
    $admin = User::factory()->superAdministrator()->create();

    $this->actingAs($admin)->postJson(route('video-exports.folders.store'), ['path' => 'ORIZABA', 'name' => 'Diciembre: 2026 / "final"'])
        ->assertCreated()
        ->assertJsonPath('path', 'ORIZABA/Diciembre 2026 final')
        ->assertJsonPath('folders', []);

    expect(Storage::disk('videos')->directoryExists('VIDEOS/ORIZABA/Diciembre 2026 final'))->toBeTrue();

    $this->actingAs($admin)->postJson(route('video-exports.folders.store'), ['path' => 'ORIZABA', 'name' => 'Diciembre 2026 final'])->assertJsonValidationErrors('name');
    $this->actingAs($admin)->postJson(route('video-exports.folders.store'), ['path' => 'ORIZABA', 'name' => '../..'])->assertJsonValidationErrors('name');
    $this->actingAs($admin)->postJson(route('video-exports.folders.store'), ['path' => '..', 'name' => 'X'])->assertJsonValidationErrors('path');

    expect(Storage::disk('videos')->directoryExists('X'))->toBeFalse();
});

test('a missing root is listed empty and created with the first folder', function () {
    app(PlatformSettings::class)->update(['video_exports' => ['root_folder' => 'Videos de solicitudes']]);
    $admin = User::factory()->superAdministrator()->create();

    $this->actingAs($admin)->getJson(route('video-exports.folders.index', ['path' => '']))->assertOk()->assertJsonPath('root', 'Videos de solicitudes')->assertJsonPath('folders', []);
    $this->actingAs($admin)->postJson(route('video-exports.folders.store'), ['path' => '', 'name' => 'ORIZABA'])->assertCreated();

    expect(Storage::disk('videos')->directoryExists('Videos de solicitudes/ORIZABA'))->toBeTrue()
        ->and(VideoFolders::normalizeRoot('a/b\\c'))->toBe('a b c')
        ->and(VideoFolders::normalizeRoot('///'))->toBe('VIDEOS');
});

test('with the NAS down the browser answers a clear 503 and touches nothing', function () {
    config(['filesystems.disks.nas' => [
        'driver' => 'local', 'root' => '/mnt/chat-manager-test-nas-down', 'visibility' => 'private',
        'mount' => ['marker' => '.chat-manager-storage', 'id' => 'test-nas', 'filesystem_types' => 'cifs'],
    ], 'attachments.storage.video_exports_disk' => 'nas']);
    app(MountHealth::class)->record('nas', 'not_mounted');
    $admin = User::factory()->superAdministrator()->create();

    $this->actingAs($admin)->getJson(route('video-exports.folders.index', ['path' => '']))
        ->assertStatus(503)
        ->assertJsonPath('code', 'storage_unavailable')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Synology no está disponible') && str_contains($message, 'no está montada'));

    $this->actingAs($admin)->postJson(route('video-exports.folders.store'), ['path' => '', 'name' => 'ORIZABA'])->assertStatus(503);
});

test('the browser opens where the user saved their last video', function () {
    $admin = User::factory()->superAdministrator()->create();
    $attachment = savedVideoAttachment(Conversation::factory()->create());
    videoExportRow($attachment, ['user_id' => $admin->id, 'folder' => 'ORIZABA/11-Noviembre 2026']);

    $this->actingAs($admin)->getJson(route('video-exports.folders.index'))->assertOk()->assertJsonPath('path', 'ORIZABA/11-Noviembre 2026');

    // A folder that no longer exists falls back to the root.
    Storage::disk('videos')->deleteDirectory('VIDEOS/ORIZABA');
    $this->actingAs($admin)->getJson(route('video-exports.folders.index'))->assertOk()->assertJsonPath('path', '');
});

test('folder segments are validated strictly', function (string $path) {
    expect(fn () => VideoFolders::segments($path))->toThrow(ValidationException::class);
})->with(['..', 'a/../b', '/a', '\\a', 'C:\\x', 'a/./b', "a\0b", 'a|b', 'a?b', '.git']);

/* ----------------------------------------------------------------- the job */

test('the job copies the video into the chosen folder and keeps the original', function () {
    $attachment = savedVideoAttachment(Conversation::factory()->create());
    $export = videoExportRow($attachment);

    runVideoExport($export);

    $path = 'VIDEOS/ORIZABA/11-Noviembre 2026/Solicitud/03-11-2026/ORIZABA SOLICITUD 03-11-2026 ULISES ABRAHAM ARZABA.mp4';

    Storage::disk('videos')->assertExists($path);
    Storage::disk('local')->assertExists($attachment->path);
    expect(Storage::disk('videos')->get($path))->toBe('video-bytes')
        ->and($export->fresh()->status)->toBe(VideoExportStatus::Saved)
        ->and($export->fresh()->target_path)->toBe($path)
        ->and($export->fresh()->saved_at)->not->toBeNull();
});

test('a name already taken gets a readable counter', function () {
    $attachment = savedVideoAttachment(Conversation::factory()->create());
    $directory = 'VIDEOS/ORIZABA/11-Noviembre 2026/Solicitud/03-11-2026';
    Storage::disk('videos')->put($directory.'/ORIZABA SOLICITUD 03-11-2026 ULISES ABRAHAM ARZABA.mp4', 'otro');

    $export = videoExportRow($attachment);
    SaveVideoToNas::dispatchSync($export->id);

    expect($export->fresh()->target_path)->toBe($directory.'/ORIZABA SOLICITUD 03-11-2026 ULISES ABRAHAM ARZABA (2).mp4')
        ->and(Storage::disk('videos')->get($directory.'/ORIZABA SOLICITUD 03-11-2026 ULISES ABRAHAM ARZABA.mp4'))->toBe('otro');
});

test('a folder removed meanwhile fails with a clear status', function () {
    $attachment = savedVideoAttachment(Conversation::factory()->create());
    $export = videoExportRow($attachment);
    Storage::disk('videos')->deleteDirectory('VIDEOS/ORIZABA');

    runVideoExport($export);

    expect($export->fresh()->status)->toBe(VideoExportStatus::Failed)
        ->and($export->fresh()->error)->toContain('ya no existe')
        ->and(File::isDirectory(Storage::disk('videos')->path('VIDEOS/ORIZABA')))->toBeFalse();
});

test('with the NAS down nothing is written: the copy waits, then fails with a clear status', function () {
    $root = '/mnt/chat-manager-test-nas-down';
    config(['filesystems.disks.nas' => [
        'driver' => 'local', 'root' => $root, 'visibility' => 'private',
        'mount' => ['marker' => '.chat-manager-storage', 'id' => 'test-nas', 'filesystem_types' => 'cifs'],
    ], 'attachments.storage.video_exports_disk' => 'nas']);
    app(MountHealth::class)->record('nas', 'not_mounted');

    $attachment = savedVideoAttachment(Conversation::factory()->create());
    $export = videoExportRow($attachment, ['disk' => 'nas']);

    $job = new SaveVideoToNas($export->id);
    runVideoExport($export, $job);

    expect($export->fresh()->status)->toBe(VideoExportStatus::Queued)
        ->and($export->fresh()->error)->toContain('Se reintentará')
        ->and(is_dir($root))->toBeFalse();

    // The last attempt gives up.
    $job->tries = 1;
    runVideoExport($export, $job);

    expect($export->fresh()->status)->toBe(VideoExportStatus::Failed)
        ->and($export->fresh()->error)->toContain('No se pudo guardar en Synology')
        ->and($export->fresh()->error)->toContain('no está montada')
        ->and(is_dir($root))->toBeFalse();
});

/* ------------------------------------------------------- the conversation */

test('a user with the permission saves a conversation video and it is audited', function () {
    Queue::fake();
    $admin = User::factory()->superAdministrator()->create();
    $conversation = Conversation::factory()->create();
    $attachment = savedVideoAttachment($conversation);

    $this->actingAs($admin)->postJson(saveVideoUrl($conversation, $attachment), ['folder' => 'ORIZABA/11-Noviembre 2026/Solicitud/03-11-2026', 'file_name' => 'Ulises Abraham Arzaba.mp4'])
        ->assertCreated()
        ->assertJsonPath('export.status', 'queued')
        ->assertJsonPath('export.label', 'Guardando…')
        ->assertJsonPath('export.file_name', 'Ulises Abraham Arzaba');

    $export = VideoExport::query()->sole();
    Queue::assertPushed(SaveVideoToNas::class, fn (SaveVideoToNas $job): bool => $job->exportId === $export->id);

    expect($export->user_id)->toBe($admin->id)
        ->and($export->disk)->toBe('videos')
        ->and(AuditLog::query()->where('action', AuditAction::VideoSaveRequested)->sole()->properties['folder'])->toBe('VIDEOS/ORIZABA/11-Noviembre 2026/Solicitud/03-11-2026');

    // While it is on its way a second request is refused.
    $this->actingAs($admin)->postJson(saveVideoUrl($conversation, $attachment), ['folder' => '', 'file_name' => 'Otro'])->assertConflict();

    $this->actingAs($admin)->getJson(route('video-exports.show', $export))->assertOk()->assertJsonPath('export.id', $export->id);
});

test('the folder and the name are validated before anything is queued', function () {
    Queue::fake();
    $admin = User::factory()->superAdministrator()->create();
    $conversation = Conversation::factory()->create();
    $attachment = savedVideoAttachment($conversation);

    $this->actingAs($admin)->postJson(saveVideoUrl($conversation, $attachment), ['folder' => '../Fuera de la raiz', 'file_name' => 'x'])->assertJsonValidationErrors('folder');
    $this->actingAs($admin)->postJson(saveVideoUrl($conversation, $attachment), ['folder' => 'NO EXISTE', 'file_name' => 'x'])->assertJsonValidationErrors('folder');
    $this->actingAs($admin)->postJson(saveVideoUrl($conversation, $attachment), ['folder' => 'ORIZABA', 'file_name' => '***'])->assertJsonValidationErrors('file_name');

    expect(VideoExport::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('the message payload offers the button only to holders of the permission', function () {
    $department = Department::factory()->create();
    $holder = conversationUser([Permission::ViewConversations, Permission::SaveVideosToNas], DataScope::Departments, [$department]);
    $other = conversationUser([Permission::ViewConversations], DataScope::Departments, [$department]);
    $conversation = Conversation::factory()->inDepartment($department)->create();
    $attachment = savedVideoAttachment($conversation);
    $message = Message::query()->with('attachments')->findOrFail($attachment->message_id);

    $payload = function (User $viewer) use ($message): mixed {
        request()->setUserResolver(fn () => $viewer);

        return (new ConversationMessageResource($message))->toArray(request())['attachments'][0]['video_save'];
    };

    expect($payload($holder))->toMatchArray(['url' => saveVideoUrl($conversation, $attachment), 'export' => null])
        ->and($payload($other))->toBeNull();
});

test('the permission and the access to the conversation are both enforced', function () {
    Queue::fake();
    $department = Department::factory()->create();
    $elsewhere = Department::factory()->create();
    $conversation = Conversation::factory()->inDepartment($department)->create();
    $attachment = savedVideoAttachment($conversation);
    $payload = ['folder' => 'ORIZABA', 'file_name' => 'Ana'];

    // Sees the conversation, but has no permission to save videos (nor to browse the NAS).
    $viewer = conversationUser([Permission::ViewConversations], DataScope::Departments, [$department]);
    $this->actingAs($viewer)->postJson(saveVideoUrl($conversation, $attachment), $payload)->assertForbidden();
    $this->actingAs($viewer)->getJson(route('video-exports.folders.index', ['path' => '']))->assertForbidden();
    $this->actingAs($viewer)->postJson(route('video-exports.folders.store'), ['path' => '', 'name' => 'X'])->assertForbidden();

    // May save videos, but not see this conversation.
    $outsider = conversationUser([Permission::ViewConversations, Permission::SaveVideosToNas], DataScope::Departments, [$elsewhere]);
    $this->actingAs($outsider)->postJson(saveVideoUrl($conversation, $attachment), $payload)->assertNotFound();

    // A message of another conversation.
    $foreign = savedVideoAttachment(Conversation::factory()->inDepartment($department)->create());
    $holder = conversationUser([Permission::ViewConversations, Permission::SaveVideosToNas], DataScope::Departments, [$department]);
    $this->actingAs($holder)->postJson(route('conversations.messages.attachments.save-video', ['conversation' => $conversation->id, 'message' => $foreign->message_id, 'attachment' => $foreign->id]), $payload)->assertNotFound();

    expect(VideoExport::query()->count())->toBe(0);

    // The status of a copy is not reachable without access either.
    $export = videoExportRow($attachment, ['status' => VideoExportStatus::Failed]);

    $this->actingAs($outsider)->getJson(route('video-exports.show', $export))->assertNotFound();
    $this->actingAs($outsider)->postJson(route('video-exports.retry', $export))->assertNotFound();
    $this->actingAs($viewer)->getJson(route('video-exports.show', $export))->assertForbidden();

    $this->actingAs($holder)->postJson(route('video-exports.retry', $export))->assertOk()->assertJsonPath('export.status', 'queued');
    Queue::assertPushed(SaveVideoToNas::class);
});

test('only videos can be saved', function () {
    $admin = User::factory()->superAdministrator()->create();
    $conversation = Conversation::factory()->create();
    $attachment = savedVideoAttachment($conversation);
    $attachment->forceFill(['mime_type' => 'image/png'])->save();

    $this->actingAs($admin)->postJson(saveVideoUrl($conversation, $attachment), ['folder' => '', 'file_name' => 'Foto'])->assertStatus(422);
});

/* -------------------------------------------------------- the internal chat */

test('a participant with the permission saves an internal chat video; others cannot', function () {
    Queue::fake();
    $role = Role::factory()->scope(DataScope::Assigned)->withPermissions([Permission::UseTeamChat, Permission::AttendControlDesk, Permission::SaveVideosToNas])->create();
    $ana = User::factory()->withRole($role)->create();
    $beto = User::factory()->agent()->create();
    $carla = User::factory()->withRole($role)->create();
    $thread = TeamThread::factory()->direct($ana, $beto)->create();
    $message = TeamMessage::factory()->in($thread, $beto)->create();
    Storage::disk('local')->put('Chat interno/x/video.mov', 'team-video');
    $attachment = TeamAttachment::factory()->create(['team_message_id' => $message->id, 'disk' => 'local', 'path' => 'Chat interno/x/video.mov', 'original_name' => 'video.mov', 'mime_type' => 'video/quicktime', 'kind' => TeamAttachmentKind::Video, 'size_bytes' => 10]);
    $url = route('team-chat.attachments.save-video', ['thread' => $thread->id, 'message' => $message->id, 'attachment' => $attachment->id]);
    $payload = ['folder' => 'CORDOBA', 'file_name' => 'María Núñez'];

    $this->actingAs($beto)->postJson($url, $payload)->assertForbidden();
    $this->actingAs($carla)->postJson($url, $payload)->assertNotFound();
    $this->actingAs($ana)->postJson($url, $payload)->assertCreated();

    $export = VideoExport::query()->sole();
    // Queue::fake() also holds dispatchSync: the job is run in place.
    runVideoExport($export);

    Storage::disk('videos')->assertExists('VIDEOS/CORDOBA/Maria Nunez.mov');
    Storage::disk('local')->assertExists('Chat interno/x/video.mov');
    expect($export->fresh()->team_attachment_id)->toBe($attachment->id);
});

/* ---------------------------------------------------------------- settings */

test('the root folder setting defaults to VIDEOS and is saved cleaned', function () {
    expect(app(PlatformSettings::class)->get('video_exports.root_folder'))->toBe('VIDEOS');

    $admin = User::factory()->withRole(role(DefaultRole::Administrator))->create();
    $settings = app(PlatformSettings::class)->all();
    $settings['video_exports']['root_folder'] = 'Videos: 2026/abc';

    $this->actingAs($admin)->put(route('admin.settings.update'), $settings)->assertSessionHasNoErrors();

    app()->forgetScopedInstances();
    expect(app(PlatformSettings::class)->get('video_exports.root_folder'))->toBe('Videos 2026 abc');
});
