<?php

namespace App\Actions\TeamChat;

use App\Enums\TeamChat\TeamThreadType;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Services\TeamChat\TeamChatPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;

/**
 * The thread list of a person: only threads where they currently
 * participate, most recent activity first, with unread counters.
 */
class TeamThreadListQuery
{
    public const FILTERS = ['all', 'unread', 'direct', 'groups', 'archived'];

    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 500;

    public function __construct(
        private TeamUnreadCounter $unread,
        private TeamChatPresenter $presenter,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:80'],
            'filter' => ['nullable', 'string', Rule::in(self::FILTERS)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ];
    }

    /**
     * @param  array{search: string, filter: string, limit: int}  $filters
     * @return array{threads: list<array<string, mixed>>, has_more: bool}
     */
    public function handle(User $viewer, array $filters): array
    {
        $query = TeamThread::query()
            ->withActiveParticipant($viewer)
            ->select('team_threads.*');

        match ($filters['filter']) {
            'archived' => $query->whereNotNull('archived_at'),
            'direct' => $query->whereNull('archived_at')->where('type', TeamThreadType::Direct),
            'groups' => $query->whereNull('archived_at')->where('type', TeamThreadType::Group),
            default => $query->whereNull('archived_at'),
        };

        if ($filters['search'] !== '') {
            $term = '%'.addcslashes($filters['search'], '\\%_').'%';

            $query->where(fn (Builder $matches) => $matches
                ->where(fn (Builder $groups) => $groups->where('type', TeamThreadType::Group)->where('name', 'like', $term))
                ->orWhereHas('activeParticipations', fn (Builder $participants) => $participants
                    ->where('user_id', '!=', $viewer->id)
                    ->whereHas('user', fn (Builder $users) => $users->where('is_active', true)->where('name', 'like', $term))));
        }

        if ($filters['filter'] === 'unread') {
            $unreadThreadIds = array_keys(array_filter($this->unread->perThread($viewer, $this->allThreadIds($viewer))));
            $query->whereKey($unreadThreadIds === [] ? [0] : $unreadThreadIds);
        }

        $threads = $query
            ->orderByRaw('coalesce(last_message_at, created_at) desc')
            ->orderByDesc('id')
            ->limit($filters['limit'] + 1)
            ->get();

        $hasMore = $threads->count() > $filters['limit'];
        $threads = $threads->take($filters['limit']);

        $threads->load([
            'activeParticipations' => fn (HasMany $participants) => $participants
                ->where(fn ($visible) => $visible
                    ->where('user_id', $viewer->id)
                    ->orWhereHas('thread', fn (Builder $thread) => $thread->where('type', TeamThreadType::Direct))),
            'activeParticipations.user:id,name,is_active,availability',
        ]);
        $threads->loadCount('activeParticipations as participants_count');

        $lastMessages = TeamMessage::query()
            ->whereKey($threads->pluck('last_message_id')->filter()->all())
            ->with('author:id,name,is_active')
            ->withCount('attachments')
            ->get()
            ->keyBy('id');

        $unread = $this->unread->perThread($viewer, array_values(array_map('intval', $threads->modelKeys())));

        return [
            'threads' => array_values($threads->map(function (TeamThread $thread) use ($viewer, $lastMessages, $unread): array {
                $me = $thread->participationOf($viewer);
                $partner = $thread->isDirect() ? $this->presenter->partner($thread, $viewer) : null;

                return [
                    'id' => $thread->id,
                    'type' => $thread->type->value,
                    'title' => $this->presenter->title($thread, $viewer),
                    'partner' => $partner === null ? null : $this->presenter->person($partner),
                    'participants_count' => (int) $thread->getAttribute('participants_count'),
                    'archived' => $thread->isArchived(),
                    'muted' => $me->muted ?? false,
                    'unread' => $unread[$thread->id] ?? 0,
                    'last_activity_at' => ($thread->last_message_at ?? $thread->created_at)?->toIso8601String(),
                    'last_message' => $this->presenter->preview($thread->last_message_id === null ? null : $lastMessages->get($thread->last_message_id), $viewer),
                ];
            })->all()),
            'has_more' => $hasMore,
        ];
    }

    /**
     * @return list<int>
     */
    private function allThreadIds(User $viewer): array
    {
        return array_values(array_map('intval', TeamThread::query()->withActiveParticipant($viewer)->whereNull('archived_at')->pluck('id')->all()));
    }
}
