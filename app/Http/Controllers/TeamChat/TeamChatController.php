<?php

namespace App\Http\Controllers\TeamChat;

use App\Actions\TeamChat\TeamMessageHistory;
use App\Actions\TeamChat\TeamThreadListQuery;
use App\Actions\TeamChat\TeamUnreadCounter;
use App\Http\Controllers\Controller;
use App\Models\TeamChat\TeamParticipant;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Services\TeamChat\TeamChatPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The internal chat page: the person's thread list and, when a thread is
 * open, its latest messages. Only current participants open a thread.
 */
class TeamChatController extends Controller
{
    public function __construct(
        private TeamThreadListQuery $list,
        private TeamMessageHistory $history,
        private TeamChatPresenter $presenter,
        private TeamUnreadCounter $unread,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', TeamThread::class);

        return $this->render($request, null);
    }

    public function show(Request $request, TeamThread $thread): Response
    {
        Gate::authorize('view', $thread);

        return $this->render($request, $thread);
    }

    private function render(Request $request, ?TeamThread $thread): Response
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $validated = $request->validate([...TeamThreadListQuery::rules(), 'around' => ['nullable', 'integer', 'min:1']]);

        $filters = [
            'search' => trim((string) ($validated['search'] ?? '')),
            'filter' => (string) ($validated['filter'] ?? 'all'),
            'limit' => (int) ($validated['limit'] ?? TeamThreadListQuery::DEFAULT_LIMIT),
        ];

        $active = null;
        $messages = null;

        if ($thread !== null) {
            $thread->load(['activeParticipations' => fn ($participants) => $participants->orderBy('joined_at'), 'activeParticipations.user:id,name,is_active,availability']);
            $canPost = Gate::inspect('post', $thread);
            $page = isset($validated['around'])
                ? $this->history->around($thread, (int) $validated['around'])
                : $this->history->latest($thread);

            $active = $this->threadDetail($thread, $viewer, $canPost->allowed(), $canPost->message());
            $messages = [
                'data' => $this->presenter->messages($page['messages'], $viewer, $canPost->allowed()),
                'has_more_before' => $page['has_more_before'],
                'has_more_after' => $page['has_more_after'],
            ];
        }

        /** @var array<string, list<string>> $types */
        $types = (array) config('team-chat.attachments.types', []);

        return Inertia::render('team-chat/Index', [
            'threads' => fn (): array => $this->list->handle($viewer, $filters),
            'filters' => $filters,
            'activeThread' => $active,
            'messages' => $messages,
            'around' => isset($validated['around']) ? (int) $validated['around'] : null,
            'unreadTotal' => fn (): int => $this->unread->total($viewer),
            'can' => [
                'createGroup' => $viewer->can('createGroup', TeamThread::class),
            ],
            'limits' => [
                'messageMaxLength' => (int) config('team-chat.message_max_length'),
                'maxFiles' => (int) config('team-chat.attachments.max_files'),
                'maxKilobytes' => (int) config('team-chat.attachments.max_kilobytes'),
                'accept' => implode(',', array_map(fn (string $extension): string => '.'.$extension, array_merge(...array_values($types)))),
                'groupMaxParticipants' => (int) config('team-chat.group_max_participants'),
                'maxThreads' => TeamThreadListQuery::MAX_LIMIT,
                'maxMentions' => (int) config('team-chat.max_mentions'),
                'reactions' => array_values(array_map('strval', (array) config('team-chat.reactions'))),
            ],
            'availabilityOptions' => $this->presenter->availabilityOptions(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function threadDetail(TeamThread $thread, User $viewer, bool $canPost, ?string $postBlockedReason): array
    {
        $me = $thread->participationOf($viewer);
        $partner = $thread->isDirect() ? $this->presenter->partner($thread, $viewer) : null;

        return [
            'id' => $thread->id,
            'type' => $thread->type->value,
            'title' => $this->presenter->title($thread, $viewer),
            'description' => $thread->description,
            'name' => $thread->name,
            'archived' => $thread->isArchived(),
            'archived_at' => $thread->archived_at?->toIso8601String(),
            'created_at' => $thread->created_at?->toIso8601String(),
            'last_message_id' => $thread->last_message_id,
            'partner' => $partner === null ? null : $this->presenter->person($partner),
            'participants' => array_values($thread->activeParticipations->map(fn (TeamParticipant $participant): array => [
                ...$this->presenter->person($participant->user),
                'role' => $participant->role->value,
                'is_me' => $participant->user_id === $viewer->id,
                'joined_at' => $participant->joined_at->toIso8601String(),
                'last_read_message_id' => $participant->last_read_message_id,
            ])->all()),
            'me' => [
                'role' => $me?->role->value,
                'muted' => $me->muted ?? false,
                'last_read_message_id' => $me?->last_read_message_id,
            ],
            'can' => [
                'post' => $canPost,
                'manage' => Gate::allows('manage', $thread),
                'archive' => Gate::allows('archive', $thread),
                'unarchive' => Gate::allows('unarchive', $thread),
                'leave' => Gate::allows('leave', $thread),
            ],
            'post_blocked_reason' => $canPost ? null : $postBlockedReason,
        ];
    }
}
