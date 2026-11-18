<?php

namespace App\Services\VideoExports;

use App\Enums\AttachmentStatus;
use App\Enums\Permission;
use App\Enums\TeamChat\TeamAttachmentKind;
use App\Enums\VideoExportStatus;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\TeamChat\TeamAttachment;
use App\Models\TeamChat\TeamMessage;
use App\Models\User;
use App\Models\VideoExport;
use App\Services\Storage\MediaPaths;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * «Guardar archivo» (born as «Guardar video»): who may copy a file of a
 * chat to the NAS (videos, images, PDFs, documents and audios), which files
 * can be copied, and what the chat bubble is told about it.
 *
 * The bubble receives `file_save` for each available attachment the viewer
 * may save (the permission videos.save-to-nas, on top of seeing the chat):
 * where to send the request, the kind of file (icon and label), the
 * extension the copy keeps, and the latest copy of that file, if any.
 * Videos also keep receiving the original `video_save` (same options, on
 * the original save-video route). Viewers without the permission get null,
 * so the button does not exist for them.
 *
 * The latest copies are read once per conversation or internal thread and
 * request (the class is scoped), never one query per bubble.
 */
#[Scoped]
class VideoExports
{
    /**
     * Latest copy per attachment id, by conversation id.
     *
     * @var array<int, array<int, VideoExport>>
     */
    private array $byConversation = [];

    /**
     * Latest copy per attachment id, by internal thread id.
     *
     * @var array<int, array<int, VideoExport>>
     */
    private array $byThread = [];

    public function permits(?User $user): bool
    {
        return $user !== null && $user->holdsPermission(Permission::SaveVideosToNas);
    }

    public function isSavableMessageAttachment(MessageAttachment $attachment): bool
    {
        $status = $attachment->getAttribute('status');

        return $attachment->path !== null && $attachment->path !== ''
            && ($status === null || $status === AttachmentStatus::Available || $status === AttachmentStatus::Available->value);
    }

    public function isSavableTeamAttachment(TeamAttachment $attachment): bool
    {
        return ! $attachment->isPurged() && $attachment->path !== null && $attachment->path !== '';
    }

    /**
     * The kind of file, for the icon and the label of the button: video,
     * image, pdf, audio or document (anything else).
     */
    public static function kindOf(MessageAttachment|TeamAttachment $attachment): string
    {
        $mime = strtolower(trim(explode(';', (string) $attachment->mime_type)[0]));

        if ($mime === '' && $attachment instanceof TeamAttachment) {
            return match ($attachment->kind) {
                TeamAttachmentKind::Video => 'video',
                TeamAttachmentKind::Image => 'image',
                TeamAttachmentKind::Audio => 'audio',
                default => 'document',
            };
        }

        return match (true) {
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'audio/') => 'audio',
            $mime === 'application/pdf' => 'pdf',
            default => 'document',
        };
    }

    /**
     * The name the file arrived with, if any.
     */
    public static function originalName(MessageAttachment|TeamAttachment $attachment): ?string
    {
        $name = $attachment instanceof MessageAttachment ? $attachment->file_name : $attachment->original_name;

        return $name === null || trim($name) === '' ? null : $name;
    }

    /**
     * The extension the copy keeps: the one of the detected type (or of the
     * original name for generic detections), never one typed by the person.
     */
    public function extensionOf(MessageAttachment|TeamAttachment $attachment): string
    {
        return app(MediaPaths::class)->extensionFor((string) $attachment->mime_type, self::originalName($attachment));
    }

    /**
     * Whether the user may still see the chat the copied video belongs to
     * (the same policies that guard the chat and its files).
     */
    public function canAccess(User $user, VideoExport $export): bool
    {
        if ($export->message_attachment_id !== null) {
            $conversation = $export->messageAttachment()->first()?->message()->first()?->conversation()->first();

            return $conversation !== null && Gate::forUser($user)->allows('view', $conversation);
        }

        $message = $export->teamAttachment()->first()?->message()->first();
        $thread = $message?->thread()->first();

        return $thread !== null && ! $message->isDeleted() && Gate::forUser($user)->allows('view', $thread);
    }

    /**
     * The folder the user saved their last file in (relative to the root),
     * where the browser opens next time; "" for the root.
     */
    public function lastFolder(User $user): string
    {
        return (string) VideoExport::query()->where('user_id', $user->id)->orderByDesc('id')->value('folder');
    }

    /**
     * What the bubble of a conversation file is told; null when the viewer
     * may not save it.
     *
     * @return array{url: string, kind: string, extension: string, name: string|null, size_bytes: int|null, export: array<string, mixed>|null}|null
     */
    public function forMessageAttachment(Message $message, MessageAttachment $attachment, ?User $viewer): ?array
    {
        if (! $this->permits($viewer) || ! $this->isSavableMessageAttachment($attachment)) {
            return null;
        }

        $latest = $this->latestInConversation($message->conversation_id)[$attachment->id] ?? null;

        return $this->options(
            route('conversations.messages.attachments.save-file', ['conversation' => $message->conversation_id, 'message' => $message->id, 'attachment' => $attachment->id]),
            $attachment,
            $latest,
        );
    }

    /**
     * The original «Guardar video» options of a conversation video (on its
     * save-video route); null for other files or viewers who may not save.
     *
     * @param  array<string, mixed>|null  $options  What forMessageAttachment() answered.
     * @return array<string, mixed>|null
     */
    public function videoForMessageAttachment(Message $message, MessageAttachment $attachment, ?array $options): ?array
    {
        return $options === null || $options['kind'] !== 'video' ? null : [
            ...$options,
            'url' => route('conversations.messages.attachments.save-video', ['conversation' => $message->conversation_id, 'message' => $message->id, 'attachment' => $attachment->id]),
        ];
    }

    /**
     * What the bubble of an internal chat file is told; null when the
     * viewer may not save it.
     *
     * @return array{url: string, kind: string, extension: string, name: string|null, size_bytes: int|null, export: array<string, mixed>|null}|null
     */
    public function forTeamAttachment(TeamMessage $message, TeamAttachment $attachment, ?User $viewer): ?array
    {
        if (! $this->permits($viewer) || $message->isDeleted() || ! $this->isSavableTeamAttachment($attachment)) {
            return null;
        }

        $latest = $this->latestInThread($message->team_thread_id)[$attachment->id] ?? null;

        return $this->options(
            route('team-chat.attachments.save-file', ['thread' => $message->team_thread_id, 'message' => $message->id, 'attachment' => $attachment->id]),
            $attachment,
            $latest,
        );
    }

    /**
     * The original «Guardar video» options of an internal chat video (on
     * its save-video route); null for other files or viewers who may not save.
     *
     * @param  array<string, mixed>|null  $options  What forTeamAttachment() answered.
     * @return array<string, mixed>|null
     */
    public function videoForTeamAttachment(TeamMessage $message, TeamAttachment $attachment, ?array $options): ?array
    {
        return $options === null || $options['kind'] !== 'video' ? null : [
            ...$options,
            'url' => route('team-chat.attachments.save-video', ['thread' => $message->team_thread_id, 'message' => $message->id, 'attachment' => $attachment->id]),
        ];
    }

    /**
     * @return array{url: string, kind: string, extension: string, name: string|null, size_bytes: int|null, export: array<string, mixed>|null}
     */
    private function options(string $url, MessageAttachment|TeamAttachment $attachment, ?VideoExport $latest): array
    {
        $size = $attachment->size_bytes;

        return [
            'url' => $url,
            'kind' => self::kindOf($attachment),
            'extension' => $this->extensionOf($attachment),
            'name' => self::originalName($attachment),
            'size_bytes' => $size === null ? null : (int) $size,
            'export' => $latest === null ? null : $this->present($latest),
        ];
    }

    /**
     * The state of a copy as the bubble shows it. The path is relative to
     * the disk of saved videos (no server paths, no disk names).
     *
     * @return array{id: int, status: string, label: string, folder: string, file_name: string, path: string|null, error: string|null, saved_at: string|null, status_url: string, retry_url: string|null}
     */
    public function present(VideoExport $export): array
    {
        return [
            'id' => $export->id,
            'status' => $export->status->value,
            'label' => $export->status->label(),
            'folder' => $export->folder,
            'file_name' => $export->file_name,
            'path' => $export->target_path,
            'error' => $export->error,
            'saved_at' => $export->saved_at?->toIso8601String(),
            'status_url' => route('video-exports.show', $export),
            'retry_url' => $export->status === VideoExportStatus::Failed ? route('video-exports.retry', $export) : null,
        ];
    }

    /**
     * Forgets what was read in this request (after a new copy is requested).
     */
    public function flush(): void
    {
        $this->byConversation = [];
        $this->byThread = [];
    }

    /**
     * @return array<int, VideoExport>
     */
    private function latestInConversation(int $conversationId): array
    {
        return $this->byConversation[$conversationId] ??= $this->latestBy(
            'message_attachment_id',
            VideoExport::query()->whereIn('message_attachment_id', MessageAttachment::query()
                ->select('message_attachments.id')
                ->join('messages', 'messages.id', '=', 'message_attachments.message_id')
                ->where('messages.conversation_id', $conversationId)),
        );
    }

    /**
     * @return array<int, VideoExport>
     */
    private function latestInThread(int $threadId): array
    {
        return $this->byThread[$threadId] ??= $this->latestBy(
            'team_attachment_id',
            VideoExport::query()->whereIn('team_attachment_id', TeamAttachment::query()
                ->select('team_attachments.id')
                ->join('team_messages', 'team_messages.id', '=', 'team_attachments.team_message_id')
                ->where('team_messages.team_thread_id', $threadId)),
        );
    }

    /**
     * @param  Builder<VideoExport>  $query
     * @return array<int, VideoExport>
     */
    private function latestBy(string $column, Builder $query): array
    {
        $latest = [];

        foreach ($query->orderByDesc('id')->get() as $export) {
            $latest[(int) $export->getAttribute($column)] ??= $export;
        }

        return $latest;
    }
}
