<?php

namespace App\Services\TeamChat;

use App\Enums\TeamChat\TeamChatSystemEvent;
use App\Models\TeamChat\TeamAttachment;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamMessageReaction;
use App\Models\TeamChat\TeamParticipant;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Support\ProtectedAccounts;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Shapes internal chat records for the interface. Deleted accounts are
 * always shown as "Usuario eliminado"; deleted messages never expose their
 * (already erased) content; attachments are only reachable through the
 * authorized download route. Protected accounts (root role) are shown as
 * «Administración del sistema» to anyone without the root authority (the
 * person viewing the page).
 */
class TeamChatPresenter
{
    public const DELETED_USER = 'Usuario eliminado';

    public function userName(?User $user): string
    {
        if ($user === null || ! $user->is_active) {
            return self::DELETED_USER;
        }

        $viewer = Auth::user();

        return (string) app(ProtectedAccounts::class)->nameFor($user, $viewer instanceof User ? $viewer : null);
    }

    /**
     * Online presence is client-side (presence channels); the legacy manual
     * availability is not exposed.
     *
     * @return array{id: int, name: string, deleted: bool}
     */
    public function person(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $this->userName($user),
            'deleted' => ! $user->is_active,
        ];
    }

    /**
     * Name of the thread for the viewer: the group name, or the other person
     * of a direct chat. Needs `activeParticipations.user` for direct chats.
     */
    public function title(TeamThread $thread, User $viewer): string
    {
        if ($thread->isGroup()) {
            return (string) $thread->name;
        }

        return $this->userName($this->partner($thread, $viewer));
    }

    public function partner(TeamThread $thread, User $viewer): ?User
    {
        $participant = $thread->activeParticipations->first(fn (TeamParticipant $participant): bool => $participant->user_id !== $viewer->id);

        return $participant?->user;
    }

    /**
     * Short, plain-text preview of a message for lists and search results.
     * Needs `author` loaded (and `attachments_count` for file-only messages).
     *
     * @return array{id: int, text: string, author: string|null, deleted: bool, system: bool, created_at: string|null}|null
     */
    public function preview(?TeamMessage $message, ?User $viewer = null, int $limit = 90): ?array
    {
        if ($message === null) {
            return null;
        }

        $text = match (true) {
            $message->isDeleted() => 'Mensaje eliminado',
            $message->isSystem() => 'Actividad del grupo',
            filled($message->body) => Str::limit(Str::squish((string) $message->body), $limit),
            default => $this->attachmentSummary((int) $message->getAttribute('attachments_count')),
        };

        return [
            'id' => $message->id,
            'text' => $text,
            'author' => $message->isSystem() ? null : ($viewer !== null && $message->user_id === $viewer->id ? 'Tú' : $this->userName($message->author)),
            'deleted' => $message->isDeleted(),
            'system' => $message->isSystem(),
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, TeamMessage>  $messages
     * @return list<array<string, mixed>>
     */
    public function messages(Collection $messages, User $viewer, bool $viewerCanPost): array
    {
        $targetIds = $messages
            ->toBase()
            ->filter(fn (TeamMessage $message): bool => $message->isSystem())
            ->map(fn (TeamMessage $message): ?int => isset($message->metadata['target_user_id']) ? (int) $message->metadata['target_user_id'] : null)
            ->merge($messages
                ->reject(fn (TeamMessage $message): bool => $message->isSystem() || $message->isDeleted())
                ->flatMap(fn (TeamMessage $message): array => $message->mentionedUserIds()))
            ->filter()
            ->unique()
            ->values()
            ->all();

        /** @var Collection<int, User> $targets */
        $targets = $targetIds === [] ? collect() : User::query()->whereKey($targetIds)->get(['id', 'name', 'is_active'])->keyBy('id');

        return array_values($messages->map(fn (TeamMessage $message): array => $this->message($message, $viewer, $viewerCanPost, $targets))->all());
    }

    /**
     * Requires `author`, `attachments`, `reactions.user` and `replyTo.author`
     * loaded. Mentioned people are looked up in `$targets`.
     *
     * @param  Collection<int, User>  $targets
     * @return array<string, mixed>
     */
    public function message(TeamMessage $message, User $viewer, bool $viewerCanPost, Collection $targets): array
    {
        $isMine = $message->user_id === $viewer->id;
        $isEditable = $viewerCanPost && $isMine && ! $message->isSystem() && ! $message->isDeleted();

        return [
            'id' => $message->id,
            'kind' => $message->kind->value,
            'body' => $message->isDeleted() ? null : $message->body,
            'system_text' => $message->isSystem() ? $this->systemText($message, $targets) : null,
            'author' => $message->isSystem() && $message->author === null ? null : [
                'id' => $message->user_id,
                'name' => $this->userName($message->author),
                'deleted' => $message->author === null || ! $message->author->is_active,
            ],
            'is_mine' => $isMine,
            'deleted' => $message->isDeleted(),
            'edited' => $message->edited_at !== null,
            'created_at' => $message->created_at?->toIso8601String(),
            'reply_to' => $message->replyTo === null ? null : [
                'id' => $message->replyTo->id,
                'author' => $this->userName($message->replyTo->author),
                'excerpt' => $message->replyTo->isDeleted()
                    ? 'Mensaje eliminado'
                    : Str::limit(Str::squish((string) $message->replyTo->body), 120),
                'deleted' => $message->replyTo->isDeleted(),
            ],
            'mentions' => $message->isSystem() || $message->isDeleted() ? [] : array_map(fn (int $userId): array => [
                'id' => $userId,
                'name' => $this->userName($targets->get($userId)),
            ], $message->mentionedUserIds()),
            'reactions' => $message->isSystem() || $message->isDeleted() ? [] : $this->reactions($message, $viewer),
            'attachments' => $message->isDeleted() ? [] : array_values($message->attachments
                ->filter(fn (TeamAttachment $attachment): bool => ! $attachment->isPurged())
                ->map(fn (TeamAttachment $attachment): array => $this->attachment($message, $attachment))
                ->all()),
            'can' => ['edit' => $isEditable, 'delete' => $isEditable],
        ];
    }

    /**
     * Reactions grouped by emoji, in the order they were first used.
     *
     * @return list<array{emoji: string, count: int, mine: bool, names: list<string>}>
     */
    public function reactions(TeamMessage $message, User $viewer): array
    {
        return array_values($message->reactions
            ->groupBy('emoji')
            ->map(fn (Collection $reactions, string $emoji): array => [
                'emoji' => $emoji,
                'count' => $reactions->count(),
                'mine' => $reactions->contains(fn (TeamMessageReaction $reaction): bool => $reaction->user_id === $viewer->id),
                'names' => array_values($reactions->map(fn (TeamMessageReaction $reaction): string => $reaction->user_id === $viewer->id ? 'Tú' : $this->userName($reaction->user))->all()),
            ])
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function attachment(TeamMessage $message, TeamAttachment $attachment): array
    {
        $parameters = ['thread' => $message->team_thread_id, 'message' => $message->id, 'attachment' => $attachment->id];

        return [
            'id' => $attachment->id,
            'name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'kind' => $attachment->kind->value,
            'size_bytes' => $attachment->size_bytes,
            'previewable' => $attachment->isPreviewable(),
            'download_url' => route('team-chat.attachments.show', $parameters),
            'preview_url' => $attachment->isPreviewable() ? route('team-chat.attachments.show', [...$parameters, 'inline' => 1]) : null,
        ];
    }

    /**
     * @param  Collection<int, User>  $targets
     */
    private function systemText(TeamMessage $message, Collection $targets): string
    {
        $metadata = $message->metadata ?? [];
        $event = TeamChatSystemEvent::tryFrom((string) ($metadata['event'] ?? ''));

        if ($event === null) {
            return 'Actividad del grupo';
        }

        $targetId = isset($metadata['target_user_id']) ? (int) $metadata['target_user_id'] : null;
        $target = $targetId === null ? null : $this->userName($targets->get($targetId));

        return $event->sentence($this->userName($message->author), $target, $metadata);
    }

    private function attachmentSummary(int $count): string
    {
        return $count === 1 ? 'Archivo adjunto' : "{$count} archivos adjuntos";
    }
}
