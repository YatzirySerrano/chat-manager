<?php

namespace App\Notifications;

use App\Models\User;
use Closure;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * The recipient's side of notifications: listing, counting and marking them
 * read. Every query is bound to the user's own notifications, so ids of
 * someone else's notifications simply match nothing.
 */
class NotificationCenter
{
    use DetectsConcurrencyErrors;

    /**
     * Attempts of the bulk "mark read" updates. They lock ranges of the
     * user's notifications, so two of them at once (the bell and the open
     * conversation both marking it read) or a notification being inserted
     * can end in a deadlock; MariaDB rolls one back and it is simply retried.
     */
    private const UPDATE_ATTEMPTS = 3;

    /** Kinds that refer to a conversation and get read when it is opened. */
    private const CONVERSATION_KINDS = [
        NotificationKind::ConversationMessage,
        NotificationKind::ConversationAssigned,
        NotificationKind::ConversationTransferred,
    ];

    /**
     * Most recently updated first (an aggregated notification moves up when
     * a new occurrence arrives). The cursor is (updated_at, id): timestamps
     * have second precision, so a burst sharing one second is not skipped
     * between pages.
     *
     * @return Collection<int, DatabaseNotification>
     */
    public function latest(User $user, int $limit, bool $unreadOnly = false, ?string $before = null, ?string $beforeId = null): Collection
    {
        return $this->ownedBy($user)
            ->when($unreadOnly, fn (Builder $query) => $query->whereNull('read_at'))
            ->when($before !== null, fn (Builder $query) => $query->where(fn (Builder $cursor) => $cursor
                ->where('updated_at', '<', $before)
                ->when($beforeId !== null, fn (Builder $tie) => $tie->orWhere(fn (Builder $same) => $same
                    ->where('updated_at', $before)
                    ->where('id', '<', $beforeId)))))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function unreadCount(User $user): int
    {
        return $this->ownedBy($user)->whereNull('read_at')->count();
    }

    /**
     * @return array{urgent: int, total: int}
     */
    public function unreadSummary(User $user): array
    {
        $counts = $this->ownedBy($user)
            ->whereNull('read_at')
            ->selectRaw('level, COUNT(*) as aggregate')
            ->groupBy('level')
            ->pluck('aggregate', 'level');

        return [
            'urgent' => (int) ($counts[NotificationLevel::Urgent->value] ?? 0),
            'total' => (int) $counts->sum(),
        ];
    }

    /**
     * Returns whether the notification was unread and is now read.
     */
    public function markRead(User $user, string $notificationId): bool
    {
        return $this->ownedBy($user)
            ->whereKey($notificationId)
            ->whereNull('read_at')
            ->toBase()->update(['read_at' => now()]) > 0;
    }

    public function markAllRead(User $user): int
    {
        return $this->retryingDeadlocks(
            fn (): int => $this->ownedBy($user)->whereNull('read_at')->toBase()->update(['read_at' => now()]),
        );
    }

    /**
     * The user opened (read) the conversation: its pending notifications are
     * no longer news.
     */
    public function markConversationRead(User $user, int $conversationId): int
    {
        return $this->retryingDeadlocks(
            fn (): int => $this->ownedBy($user)
                ->whereNull('read_at')
                ->whereIn('kind', array_map(fn (NotificationKind $kind): string => $kind->value, self::CONVERSATION_KINDS))
                ->where('data->conversation_id', $conversationId)
                ->toBase()->update(['read_at' => now()]),
        );
    }

    /**
     * Runs an idempotent update again when the database chose it as the
     * victim of a deadlock or a lock wait (bounded attempts, short pause).
     *
     * @param  Closure(): int  $update
     */
    private function retryingDeadlocks(Closure $update): int
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $update();
            } catch (QueryException $exception) {
                if ($attempt >= self::UPDATE_ATTEMPTS || ! $this->causedByConcurrencyError($exception)) {
                    throw $exception;
                }

                usleep(20_000 * $attempt);
            }
        }
    }

    /**
     * @return Builder<DatabaseNotification>
     */
    private function ownedBy(User $user): Builder
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->id);
    }
}
