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
 *
 * `filter` is the scope: all, direct, groups or archived. Two toggles
 * combine with it: `unread` (threads with messages unread for the person)
 * and `mentions` (threads with unread messages that mention them). The
 * legacy `filter=unread` and `filter=mentions` still work: they are the
 * toggle over every thread that is not archived (normalize()). counts()
 * gives the quick filters their numbers within the scope.
 */
class TeamThreadListQuery
{
    public const FILTERS = ['all', 'unread', 'mentions', 'direct', 'groups', 'archived'];

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
            'unread' => ['nullable', 'boolean'],
            'mentions' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ];
    }

    /**
     * The filters of the list from validated input: the scope and the
     * toggles, the legacy `filter=unread|mentions` turned into its toggle.
     *
     * @param  array<string, mixed>  $validated
     * @return array{search: string, filter: string, unread: bool, mentions: bool, limit: int}
     */
    public static function normalize(array $validated): array
    {
        $filter = is_string($validated['filter'] ?? null) ? $validated['filter'] : 'all';

        return [
            'search' => trim(is_string($validated['search'] ?? null) ? $validated['search'] : ''),
            'filter' => in_array($filter, ['unread', 'mentions'], true) ? 'all' : $filter,
            'unread' => $filter === 'unread' || filter_var($validated['unread'] ?? false, FILTER_VALIDATE_BOOL),
            'mentions' => $filter === 'mentions' || filter_var($validated['mentions'] ?? false, FILTER_VALIDATE_BOOL),
            'limit' => is_numeric($validated['limit'] ?? null) ? (int) $validated['limit'] : self::DEFAULT_LIMIT,
        ];
    }

    /**
     * @param  array{search: string, filter: string, unread?: bool, mentions?: bool, limit: int}  $filters
     * @return array{threads: list<array<string, mixed>>, has_more: bool}
     */
    public function handle(User $viewer, array $filters): array
    {
        $query = $this->scoped($viewer, $filters['filter'])
            ->select('team_threads.*');

        if ($filters['search'] !== '') {
            $term = '%'.addcslashes($filters['search'], '\\%_').'%';

            $query->where(fn (Builder $matches) => $matches
                ->where(fn (Builder $groups) => $groups->where('type', TeamThreadType::Group)->where('name', 'like', $term))
                ->orWhereHas('activeParticipations', fn (Builder $participants) => $participants
                    ->where('user_id', '!=', $viewer->id)
                    // A protected account (root role) is never found by its real
                    // name by anyone else (they see it as the system administration).
                    ->whereHas('user', fn (Builder $users) => $users->where('is_active', true)->withoutProtectedFor($viewer)->where('name', 'like', $term))));
        }

        // The toggles narrow to the threads of the scope with unread messages
        // (or unread mentions) for the person; both together mean both.
        if (($filters['unread'] ?? false) || ($filters['mentions'] ?? false)) {
            $ids = $this->threadIds($viewer, $filters['filter']);

            foreach (['unread' => 'perThread', 'mentions' => 'mentionsPerThread'] as $toggle => $counter) {
                if ($filters[$toggle] ?? false) {
                    $ids = array_keys(array_filter($this->unread->{$counter}($viewer, $ids)));
                }
            }

            $query->whereKey($ids === [] ? [0] : $ids);
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
            'activeParticipations.user:id,name,is_active',
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
     * Numbers of the quick filters, over the person's threads that are not
     * archived: threads with unread messages and threads with unread
     * messages that mention them (muted ones included, as the rows show).
     *
     * @return array{unread: int, mentions: int}
     */
    public function counts(User $viewer, string $scope = 'all'): array
    {
        $ids = $this->threadIds($viewer, $scope);

        return [
            'unread' => count(array_filter($this->unread->perThread($viewer, $ids))),
            'mentions' => count(array_filter($this->unread->mentionsPerThread($viewer, $ids))),
        ];
    }

    /**
     * The person's threads within the scope (all, direct, groups, archived).
     *
     * @return Builder<TeamThread>
     */
    private function scoped(User $viewer, string $scope): Builder
    {
        $query = TeamThread::query()->withActiveParticipant($viewer);

        return match ($scope) {
            'archived' => $query->whereNotNull('archived_at'),
            'direct' => $query->whereNull('archived_at')->where('type', TeamThreadType::Direct),
            'groups' => $query->whereNull('archived_at')->where('type', TeamThreadType::Group),
            default => $query->whereNull('archived_at'),
        };
    }

    /**
     * @return list<int>
     */
    private function threadIds(User $viewer, string $scope): array
    {
        return array_values(array_map('intval', $this->scoped($viewer, $scope)->pluck('team_threads.id')->all()));
    }
}
