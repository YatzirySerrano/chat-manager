<?php

namespace App\Actions\TeamChat;

use App\Enums\TeamChat\TeamDeskStatus;
use App\Enums\TeamChat\TeamThreadType;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Services\TeamChat\ControlDesk;
use App\Services\TeamChat\TeamChatPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * The «Mesa de control» section of an auditor, like the customer inbox:
 *
 * - `pending` (default): every request waiting to be accepted, the newest
 *   request on top (`desk_requested_at` desc).
 * - `mine`: the open requests she attends, the one waiting longest on top
 *   (`desk_requested_at` asc), with her unread counters.
 * - `completed`: completed requests, the most recently completed first.
 *
 * Counters of the three tabs and her capacity (open desk requests against
 * the simultaneous-attention limit, ControlDesk::limit()) come with it.
 */
class DeskInboxQuery
{
    public const TABS = ['pending', 'mine', 'completed'];

    public const LIMIT = 100;

    public function __construct(
        private ControlDesk $desk,
        private TeamChatPresenter $presenter,
        private TeamUnreadCounter $unread,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return ['desk' => ['nullable', 'string', Rule::in(self::TABS)]];
    }

    /**
     * @return array{tab: string, threads: list<array<string, mixed>>, has_more: bool, counts: array{pending: int, mine: int, completed: int}, capacity: array{limit: int|null, load: int}}
     */
    public function handle(User $viewer, string $tab): array
    {
        $tab = in_array($tab, self::TABS, true) ? $tab : 'pending';

        $threads = $this->tab($viewer, $tab)
            ->with(['deskRequester:id,name,is_active', 'deskAssignee:id,name,is_active', 'deskCompletedBy:id,name,is_active'])
            ->limit(self::LIMIT + 1)
            ->get();

        $hasMore = $threads->count() > self::LIMIT;
        $threads = $threads->take(self::LIMIT);

        $lastMessages = TeamMessage::query()
            ->whereKey($threads->pluck('last_message_id')->filter()->all())
            ->with('author:id,name,is_active')
            ->withCount('attachments')
            ->get()
            ->keyBy('id');

        $unread = $this->unread->perThread($viewer, array_values(array_map('intval', $threads->modelKeys())));

        return [
            'tab' => $tab,
            'threads' => array_values($threads->map(fn (TeamThread $thread): array => [
                'id' => $thread->id,
                'title' => $this->presenter->title($thread, $viewer),
                'unread' => $unread[$thread->id] ?? 0,
                'last_activity_at' => ($thread->last_message_at ?? $thread->created_at)?->toIso8601String(),
                'last_message' => $this->presenter->preview($thread->last_message_id === null ? null : $lastMessages->get($thread->last_message_id), $viewer),
                'desk' => $this->presenter->desk($thread, $viewer),
            ])->all()),
            'has_more' => $hasMore,
            'counts' => [
                'pending' => $this->base($viewer)->where('desk_status', TeamDeskStatus::Pending)->count(),
                'mine' => $this->desk->load($viewer),
                'completed' => $this->base($viewer)->where('desk_status', TeamDeskStatus::Closed)->count(),
            ],
            'capacity' => [
                'limit' => $this->desk->limit($viewer),
                'load' => $this->desk->load($viewer),
            ],
        ];
    }

    /**
     * Number of requests waiting, for the badge of the section.
     */
    public function pendingCount(User $viewer): int
    {
        return $this->base($viewer)->where('desk_status', TeamDeskStatus::Pending)->count();
    }

    /**
     * @return Builder<TeamThread>
     */
    private function tab(User $viewer, string $tab): Builder
    {
        return match ($tab) {
            'mine' => $this->base($viewer)
                ->where('desk_status', TeamDeskStatus::Open)
                ->where('desk_assigned_user_id', $viewer->id)
                ->orderBy('desk_requested_at')
                ->orderBy('id'),
            'completed' => $this->base($viewer)
                ->where('desk_status', TeamDeskStatus::Closed)
                ->orderByDesc('desk_completed_at')
                ->orderByDesc('id'),
            default => $this->base($viewer)
                ->where('desk_status', TeamDeskStatus::Pending)
                ->orderByDesc('desk_requested_at')
                ->orderByDesc('id'),
        };
    }

    /**
     * Every desk thread but the viewer's own: an auditor who writes to the
     * desk herself is attended by the others and reaches it as a requester.
     *
     * @return Builder<TeamThread>
     */
    private function base(User $viewer): Builder
    {
        return TeamThread::query()
            ->where('type', TeamThreadType::Desk)
            ->where(fn (Builder $query) => $query->whereNull('desk_requester_id')->orWhere('desk_requester_id', '!=', $viewer->id));
    }
}
