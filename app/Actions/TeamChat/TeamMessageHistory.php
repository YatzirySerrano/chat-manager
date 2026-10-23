<?php

namespace App\Actions\TeamChat;

use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamThread;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Cursor pagination of a thread's history by message id (stable while new
 * messages arrive): the latest page, the page before or after a message,
 * or the page around a message (to open a search result).
 */
class TeamMessageHistory
{
    /**
     * @return array{messages: Collection<int, TeamMessage>, has_more_before: bool, has_more_after: bool}
     */
    public function latest(TeamThread $thread, ?int $limit = null): array
    {
        $limit ??= $this->pageSize();
        $messages = $this->base($thread)->orderByDesc('id')->limit($limit + 1)->get();

        return [
            'messages' => $messages->take($limit)->reverse()->values(),
            'has_more_before' => $messages->count() > $limit,
            'has_more_after' => false,
        ];
    }

    /**
     * @return array{messages: Collection<int, TeamMessage>, has_more_before: bool, has_more_after: bool}
     */
    public function before(TeamThread $thread, int $messageId, ?int $limit = null): array
    {
        $limit ??= $this->pageSize();
        $messages = $this->base($thread)->where('id', '<', $messageId)->orderByDesc('id')->limit($limit + 1)->get();

        return [
            'messages' => $messages->take($limit)->reverse()->values(),
            'has_more_before' => $messages->count() > $limit,
            'has_more_after' => true,
        ];
    }

    /**
     * @return array{messages: Collection<int, TeamMessage>, has_more_before: bool, has_more_after: bool}
     */
    public function after(TeamThread $thread, int $messageId, ?int $limit = null): array
    {
        $limit ??= $this->pageSize();
        $messages = $this->base($thread)->where('id', '>', $messageId)->orderBy('id')->limit($limit + 1)->get();

        return [
            'messages' => $messages->take($limit)->values(),
            'has_more_before' => true,
            'has_more_after' => $messages->count() > $limit,
        ];
    }

    /**
     * The page centered on a message of the thread (falls back to the
     * latest page when the message is not part of it).
     *
     * @return array{messages: Collection<int, TeamMessage>, has_more_before: bool, has_more_after: bool}
     */
    public function around(TeamThread $thread, int $messageId): array
    {
        if (! $thread->messages()->whereKey($messageId)->exists()) {
            return $this->latest($thread);
        }

        $half = intdiv($this->pageSize(), 2);
        $older = $this->base($thread)->where('id', '<', $messageId)->orderByDesc('id')->limit($half + 1)->get();
        $newer = $this->base($thread)->where('id', '>=', $messageId)->orderBy('id')->limit($half + 1)->get();

        return [
            'messages' => $older->take($half)->reverse()->values()->concat($newer->take($half))->values(),
            'has_more_before' => $older->count() > $half,
            'has_more_after' => $newer->count() > $half,
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, TeamMessage>
     */
    public function only(TeamThread $thread, array $ids): Collection
    {
        return $this->base($thread)->whereKey($ids)->orderBy('id')->get();
    }

    /**
     * @return Builder<TeamMessage>
     */
    private function base(TeamThread $thread): Builder
    {
        return TeamMessage::query()
            ->where('team_thread_id', $thread->id)
            ->with([
                'author:id,name,is_active',
                'attachments',
                'reactions' => fn ($reactions) => $reactions->orderBy('id'),
                'reactions.user:id,name,is_active',
                'replyTo:id,team_thread_id,user_id,body,deleted_at',
                'replyTo.author:id,name,is_active',
            ]);
    }

    private function pageSize(): int
    {
        return max(5, (int) config('team-chat.page_size', 30));
    }
}
