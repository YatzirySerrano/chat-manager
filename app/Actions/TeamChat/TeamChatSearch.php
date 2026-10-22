<?php

namespace App\Actions\TeamChat;

use App\Enums\TeamChat\TeamMessageKind;
use App\Enums\TeamChat\TeamThreadType;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Services\TeamChat\TeamChatPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Search inside the internal chat, limited to threads where the person
 * currently participates: nothing from other threads is matched, counted
 * or hinted at. Deleted messages and system lines are never matched.
 */
class TeamChatSearch
{
    public const MIN_LENGTH = 2;

    public const LIMIT = 20;

    public function __construct(private TeamChatPresenter $presenter) {}

    /**
     * @return list<array{thread_id: int, thread_title: string, thread_type: string, message_id: int, author: string, excerpt: string, created_at: string|null}>
     */
    public function messages(User $viewer, string $search): array
    {
        $search = Str::squish($search);

        if (mb_strlen($search) < self::MIN_LENGTH) {
            return [];
        }

        $term = '%'.addcslashes($search, '\\%_').'%';

        $messages = TeamMessage::query()
            ->whereIn('team_thread_id', TeamThread::query()->withActiveParticipant($viewer)->select('id'))
            ->where('kind', TeamMessageKind::Message)
            ->whereNull('deleted_at')
            ->where('body', 'like', $term)
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->with([
                'author:id,name,is_active',
                'thread',
                'thread.activeParticipations' => fn ($participants) => $participants->whereHas('thread', fn (Builder $thread) => $thread->where('type', TeamThreadType::Direct)),
                'thread.activeParticipations.user:id,name,is_active',
            ])
            ->get();

        return array_values($messages->map(fn (TeamMessage $message): array => [
            'thread_id' => $message->team_thread_id,
            'thread_title' => $this->presenter->title($message->thread, $viewer),
            'thread_type' => $message->thread->type->value,
            'message_id' => $message->id,
            'author' => $message->user_id === $viewer->id ? 'Tú' : $this->presenter->userName($message->author),
            'excerpt' => $this->excerpt((string) $message->body, $search),
            'created_at' => $message->created_at?->toIso8601String(),
        ])->all());
    }

    /**
     * A window of the text around the first match.
     */
    private function excerpt(string $body, string $search): string
    {
        $body = Str::squish($body);
        $position = mb_stripos($body, $search);

        if ($position === false || $position < 40) {
            return Str::limit($body, 140);
        }

        return '…'.Str::limit(mb_substr($body, $position - 40), 140);
    }
}
