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
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * «Guardar video»: who may copy a video of a chat to the NAS, which videos
 * can be copied, and what the chat bubble is told about it.
 *
 * The bubble receives `video_save` for each video attachment the viewer may
 * save (the permission videos.save-to-nas, on top of seeing the chat):
 * where to send the request and the latest copy of that video, if any.
 * Viewers without the permission get null, so the button does not exist
 * for them.
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
            && ($status === null || $status === AttachmentStatus::Available || $status === AttachmentStatus::Available->value)
            && str_starts_with(strtolower((string) $attachment->mime_type), 'video/');
    }

    public function isSavableTeamAttachment(TeamAttachment $attachment): bool
    {
        return ! $attachment->isPurged() && $attachment->kind === TeamAttachmentKind::Video;
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
     * The folder the user saved their last video in (relative to the root),
     * where the browser opens next time; "" for the root.
     */
    public function lastFolder(User $user): string
    {
        return (string) VideoExport::query()->where('user_id', $user->id)->orderByDesc('id')->value('folder');
    }

    /**
     * What the bubble of a conversation video is told; null when the viewer
     * may not save it.
     *
     * @return array{url: string, export: array<string, mixed>|null}|null
     */
    public function forMessageAttachment(Message $message, MessageAttachment $attachment, ?User $viewer): ?array
    {
        if (! $this->permits($viewer) || ! $this->isSavableMessageAttachment($attachment)) {
            return null;
        }

        $latest = $this->latestInConversation($message->conversation_id)[$attachment->id] ?? null;

        return [
            'url' => route('conversations.messages.attachments.save-video', ['conversation' => $message->conversation_id, 'message' => $message->id, 'attachment' => $attachment->id]),
            'export' => $latest === null ? null : $this->present($latest),
        ];
    }

    /**
     * What the bubble of an internal chat video is told; null when the
     * viewer may not save it.
     *
     * @return array{url: string, export: array<string, mixed>|null}|null
     */
    public function forTeamAttachment(TeamMessage $message, TeamAttachment $attachment, ?User $viewer): ?array
    {
        if (! $this->permits($viewer) || $message->isDeleted() || ! $this->isSavableTeamAttachment($attachment)) {
            return null;
        }

        $latest = $this->latestInThread($message->team_thread_id)[$attachment->id] ?? null;

        return [
            'url' => route('team-chat.attachments.save-video', ['thread' => $message->team_thread_id, 'message' => $message->id, 'attachment' => $attachment->id]),
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
