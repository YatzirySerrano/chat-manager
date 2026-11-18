<?php

use App\Enums\AuditAction;
use App\Enums\DataScope;
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
use App\Services\Storage\MediaDisk;
use App\Services\TeamChat\TeamChatPresenter;
use App\Services\VideoExports\VideoFolders;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
| «Guardar archivo»: the «Guardar video» flow for every kind of attachment
| (images, PDFs, documents, audios). The copy keeps the extension of the
| original whatever the person types, the bubble is told the kind of file,
| and the permission and the access to the chat are enforced as for videos.
*/

beforeEach(function () {
    Storage::fake('local');
    config([
        'filesystems.media_disk' => 'local',
        'filesystems.disks.videos' => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/videos'), 'visibility' => 'private'],
        'attachments.storage.video_exports_disk' => 'videos',
    ]);
    Storage::fake('videos');
    Storage::disk('videos')->makeDirectory('VIDEOS/ORIZABA/Solicitud');
});

function conversationFile(Conversation $conversation, string $type, string $path, string $mime, ?string $fileName = null): MessageAttachment
{
    $message = Message::factory()->create(['conversation_id' => $conversation->id, 'direction' => 'inbound', 'type' => $type, 'status' => 'received', 'body' => null]);
    Storage::disk('local')->put($path, $type.'-bytes');
    $attachment = new MessageAttachment;
    $attachment->forceFill(['message_id' => $message->id, 'disk' => 'local', 'path' => $path, 'mime_type' => $mime, 'file_name' => $fileName, 'size_bytes' => 2048])->save();

    return $attachment;
}

function saveFileUrl(Conversation $conversation, MessageAttachment $attachment): string
{
    return route('conversations.messages.attachments.save-file', ['conversation' => $conversation->id, 'message' => $attachment->message_id, 'attachment' => $attachment->id]);
}

function runFileExport(VideoExport $export): void
{
    (new SaveVideoToNas($export->id))->handle(app(MediaDisk::class), app(VideoFolders::class));
}

test('an image of a conversation is saved with its own extension', function () {
    Queue::fake();
    $admin = User::factory()->superAdministrator()->create();
    $conversation = Conversation::factory()->create();
    $attachment = conversationFile($conversation, 'image', 'WhatsApp/Ana/foto.jpg', 'image/jpeg', 'IMG-2026.jpeg');

    // A typed extension of the same file is not doubled.
    $this->actingAs($admin)->postJson(saveFileUrl($conversation, $attachment), ['folder' => 'ORIZABA/Solicitud', 'file_name' => 'INE Ana López.jpg'])
        ->assertCreated()
        ->assertJsonPath('export.status', 'queued')
        ->assertJsonPath('export.file_name', 'INE Ana Lopez');

    $export = VideoExport::query()->sole();
    expect(AuditLog::query()->where('action', AuditAction::VideoSaveRequested)->sole()->properties['kind'])->toBe('image');

    runFileExport($export);

    Storage::disk('videos')->assertExists('VIDEOS/ORIZABA/Solicitud/INE Ana Lopez.jpg');
    Storage::disk('local')->assertExists($attachment->path);
    expect($export->fresh()->status)->toBe(VideoExportStatus::Saved)
        ->and(Storage::disk('videos')->get('VIDEOS/ORIZABA/Solicitud/INE Ana Lopez.jpg'))->toBe('image-bytes');
});

test('a PDF keeps .pdf even when another extension is typed', function () {
    Queue::fake();
    $admin = User::factory()->superAdministrator()->create();
    $conversation = Conversation::factory()->create();
    $attachment = conversationFile($conversation, 'document', 'WhatsApp/Ana/comprobante.pdf', 'application/pdf', 'Comprobante domicilio.pdf');

    $this->actingAs($admin)->postJson(saveFileUrl($conversation, $attachment), ['folder' => 'ORIZABA', 'file_name' => 'Comprobante.docx'])->assertCreated();

    runFileExport(VideoExport::query()->sole());

    // ".docx" is part of the typed name (cleaned), the extension is the original's.
    Storage::disk('videos')->assertExists('VIDEOS/ORIZABA/Comprobante.docx.pdf');
});

test('a generic Office document keeps the extension of its original name', function () {
    Queue::fake();
    $admin = User::factory()->superAdministrator()->create();
    $conversation = Conversation::factory()->create();
    $attachment = conversationFile($conversation, 'document', 'WhatsApp/Ana/contrato.bin', 'application/octet-stream', 'Contrato firmado.xlsx');

    $this->actingAs($admin)->postJson(saveFileUrl($conversation, $attachment), ['folder' => '', 'file_name' => 'Contrato Ana.xlsx'])->assertCreated();

    runFileExport(VideoExport::query()->sole());

    Storage::disk('videos')->assertExists('VIDEOS/Contrato Ana.xlsx');
});

test('the message payload tells the kind and the extension of every file', function () {
    $department = Department::factory()->create();
    $holder = conversationUser([Permission::ViewConversations, Permission::SaveVideosToNas], DataScope::Departments, [$department]);
    $other = conversationUser([Permission::ViewConversations], DataScope::Departments, [$department]);
    $conversation = Conversation::factory()->inDepartment($department)->create();

    $payload = function (MessageAttachment $attachment, User $viewer): array {
        request()->setUserResolver(fn () => $viewer);
        $message = Message::query()->with('attachments')->findOrFail($attachment->message_id);

        return (new ConversationMessageResource($message))->toArray(request())['attachments'][0];
    };

    $image = conversationFile($conversation, 'image', 'WhatsApp/Ana/foto.png', 'image/png');
    $pdf = conversationFile($conversation, 'document', 'WhatsApp/Ana/recibo.pdf', 'application/pdf', 'Recibo.pdf');
    $audio = conversationFile($conversation, 'audio', 'WhatsApp/Ana/nota.ogg', 'audio/ogg');
    $word = conversationFile($conversation, 'document', 'WhatsApp/Ana/carta.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'Carta.docx');
    $video = conversationFile($conversation, 'video', 'WhatsApp/Ana/video.mp4', 'video/mp4');

    expect($payload($image, $holder)['file_save'])->toMatchArray(['url' => saveFileUrl($conversation, $image), 'kind' => 'image', 'extension' => 'png', 'export' => null])
        ->and($payload($image, $holder)['video_save'])->toBeNull()
        ->and($payload($pdf, $holder)['file_save'])->toMatchArray(['kind' => 'pdf', 'extension' => 'pdf', 'name' => 'Recibo.pdf', 'size_bytes' => 2048])
        ->and($payload($audio, $holder)['file_save'])->toMatchArray(['kind' => 'audio', 'extension' => 'ogg'])
        ->and($payload($word, $holder)['file_save'])->toMatchArray(['kind' => 'document', 'extension' => 'docx'])
        ->and($payload($video, $holder)['file_save']['kind'])->toBe('video')
        // The original «Guardar video» options keep their route.
        ->and($payload($video, $holder)['video_save']['url'])->toBe(route('conversations.messages.attachments.save-video', ['conversation' => $conversation->id, 'message' => $video->message_id, 'attachment' => $video->id]))
        ->and($payload($image, $other)['file_save'])->toBeNull()
        ->and($payload($video, $other)['video_save'])->toBeNull();
});

test('saving an image enforces the permission and the access to the conversation', function () {
    Queue::fake();
    $department = Department::factory()->create();
    $elsewhere = Department::factory()->create();
    $conversation = Conversation::factory()->inDepartment($department)->create();
    $attachment = conversationFile($conversation, 'image', 'WhatsApp/Ana/foto.jpg', 'image/jpeg');
    $body = ['folder' => 'ORIZABA', 'file_name' => 'Foto'];

    $viewer = conversationUser([Permission::ViewConversations], DataScope::Departments, [$department]);
    $this->actingAs($viewer)->postJson(saveFileUrl($conversation, $attachment), $body)->assertForbidden();

    $outsider = conversationUser([Permission::ViewConversations, Permission::SaveVideosToNas], DataScope::Departments, [$elsewhere]);
    $this->actingAs($outsider)->postJson(saveFileUrl($conversation, $attachment), $body)->assertNotFound();

    expect(VideoExport::query()->count())->toBe(0);

    // The original save-video route accepts any file too.
    $holder = conversationUser([Permission::ViewConversations, Permission::SaveVideosToNas], DataScope::Departments, [$department]);
    $this->actingAs($holder)->postJson(route('conversations.messages.attachments.save-video', ['conversation' => $conversation->id, 'message' => $attachment->message_id, 'attachment' => $attachment->id]), $body)->assertCreated();

    // While it is on its way a second request is refused.
    $this->actingAs($holder)->postJson(saveFileUrl($conversation, $attachment), $body)->assertConflict()->assertJsonPath('message', 'Este archivo ya se está guardando en Synology.');
    Queue::assertPushed(SaveVideoToNas::class);
});

test('a PDF of the internal chat is saved by a participant with the permission only', function () {
    Queue::fake();
    $role = Role::factory()->scope(DataScope::Assigned)->withPermissions([Permission::UseTeamChat, Permission::AttendControlDesk, Permission::SaveVideosToNas])->create();
    $ana = User::factory()->withRole($role)->create();
    $beto = User::factory()->agent()->create();
    $carla = User::factory()->withRole($role)->create();
    $thread = TeamThread::factory()->direct($ana, $beto)->create();
    $message = TeamMessage::factory()->in($thread, $beto)->create();
    Storage::disk('local')->put('Chat interno/x/estado.pdf', 'team-pdf');
    $attachment = TeamAttachment::factory()->create(['team_message_id' => $message->id, 'disk' => 'local', 'path' => 'Chat interno/x/estado.pdf', 'original_name' => 'Estado de cuenta.pdf', 'mime_type' => 'application/pdf', 'kind' => TeamAttachmentKind::Document, 'size_bytes' => 8]);
    $url = route('team-chat.attachments.save-file', ['thread' => $thread->id, 'message' => $message->id, 'attachment' => $attachment->id]);
    $body = ['folder' => 'ORIZABA/Solicitud', 'file_name' => 'Estado de cuenta María.pdf'];

    expect(app(TeamChatPresenter::class)->attachment($message, $attachment, $ana)['file_save'])->toMatchArray(['url' => $url, 'kind' => 'pdf', 'extension' => 'pdf', 'name' => 'Estado de cuenta.pdf'])
        ->and(app(TeamChatPresenter::class)->attachment($message, $attachment, $ana)['video_save'])->toBeNull()
        ->and(app(TeamChatPresenter::class)->attachment($message, $attachment, $beto)['file_save'])->toBeNull();

    $this->actingAs($beto)->postJson($url, $body)->assertForbidden();
    $this->actingAs($carla)->postJson($url, $body)->assertNotFound();
    $this->actingAs($ana)->postJson($url, $body)->assertCreated();

    runFileExport(VideoExport::query()->sole());

    Storage::disk('videos')->assertExists('VIDEOS/ORIZABA/Solicitud/Estado de cuenta Maria.pdf');
    Storage::disk('local')->assertExists('Chat interno/x/estado.pdf');
});

test('an image of the internal chat keeps its extension', function () {
    Queue::fake();
    $role = Role::factory()->scope(DataScope::Assigned)->withPermissions([Permission::UseTeamChat, Permission::AttendControlDesk, Permission::SaveVideosToNas])->create();
    $ana = User::factory()->withRole($role)->create();
    $beto = User::factory()->agent()->create();
    $thread = TeamThread::factory()->direct($ana, $beto)->create();
    $message = TeamMessage::factory()->in($thread, $beto)->create();
    Storage::disk('local')->put('Chat interno/x/foto.webp', 'team-image');
    $attachment = TeamAttachment::factory()->create(['team_message_id' => $message->id, 'disk' => 'local', 'path' => 'Chat interno/x/foto.webp', 'original_name' => 'foto.webp', 'mime_type' => 'image/webp', 'kind' => TeamAttachmentKind::Image, 'size_bytes' => 10]);

    $this->actingAs($ana)->postJson(route('team-chat.attachments.save-file', ['thread' => $thread->id, 'message' => $message->id, 'attachment' => $attachment->id]), ['folder' => '', 'file_name' => 'Fachada'])->assertCreated();

    runFileExport(VideoExport::query()->sole());

    Storage::disk('videos')->assertExists('VIDEOS/Fachada.webp');
});
