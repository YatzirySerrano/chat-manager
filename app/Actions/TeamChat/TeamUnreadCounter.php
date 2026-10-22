<?php

namespace App\Actions\TeamChat;

use App\Enums\TeamChat\TeamMessageKind;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Unread internal messages of a person: messages written by somebody else
 * after their read marker, in threads where they currently participate.
 * System lines and deleted messages never count; muted threads are left
 * out of the global badge.
 */
class TeamUnreadCounter
{
    /**
     * Unread messages for the sidebar badge (muted threads excluded).
     */
    public function total(User $user): int
    {
        return (int) $this->unreadMessages($user)->where('p.muted', false)->count();
    }

    /**
     * Unread messages per thread id (muted ones included).
     *
     * @param  list<int>  $threadIds
     * @return array<int, int>
     */
    public function perThread(User $user, array $threadIds): array
    {
        if ($threadIds === []) {
            return [];
        }

        return $this->unreadMessages($user)
            ->whereIn('m.team_thread_id', $threadIds)
            ->groupBy('m.team_thread_id')
            ->pluck(DB::raw('count(*) as unread'), 'm.team_thread_id')
            ->mapWithKeys(fn (mixed $count, int|string $threadId): array => [(int) $threadId => (int) $count])
            ->all();
    }

    private function unreadMessages(User $user): Builder
    {
        return DB::table('team_messages as m')
            ->join('team_participants as p', function (JoinClause $join) use ($user): void {
                $join->on('p.team_thread_id', '=', 'm.team_thread_id')
                    ->where('p.user_id', '=', $user->id)
                    ->whereNull('p.left_at');
            })
            ->where('m.kind', TeamMessageKind::Message->value)
            ->whereNull('m.deleted_at')
            ->where(fn (Builder $authors) => $authors->whereNull('m.user_id')->orWhere('m.user_id', '!=', $user->id))
            ->whereRaw('m.id > coalesce(p.last_read_message_id, 0)');
    }
}
