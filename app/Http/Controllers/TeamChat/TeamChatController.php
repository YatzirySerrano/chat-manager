<?php

namespace App\Http\Controllers\TeamChat;

use App\Actions\TeamChat\DeskInboxQuery;
use App\Actions\TeamChat\TeamMessageHistory;
use App\Actions\TeamChat\TeamThreadListQuery;
use App\Actions\TeamChat\TeamUnreadCounter;
use App\Enums\Permission;
use App\Enums\TeamChat\TeamDeskStatus;
use App\Http\Controllers\Controller;
use App\Models\TeamChat\TeamParticipant;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Services\Personalization\ChatAppearances;
use App\Services\TeamChat\ControlDesk;
use App\Services\TeamChat\TeamChatPresenter;
use App\Support\Attachments\ServerUploadLimits;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The internal chat page: the person's thread list and, when a thread is
 * open, its latest messages. Only current participants open a thread
 * (desk threads: their requester and the auditors, see TeamThreadPolicy).
 * A requester's list is her «Mesa de control» thread alone; auditors get
 * the desk section (`desk` tab, DeskInboxQuery) besides their chats.
 */
class TeamChatController extends Controller
{
    public function __construct(
        private TeamThreadListQuery $list,
        private TeamMessageHistory $history,
        private TeamChatPresenter $presenter,
        private TeamUnreadCounter $unread,
        private ChatAppearances $appearances,
        private ControlDesk $desk,
        private DeskInboxQuery $deskInbox,
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
        $validated = $request->validate([...TeamThreadListQuery::rules(), ...DeskInboxQuery::rules(), 'around' => ['nullable', 'integer', 'min:1']]);

        // The scope (`filter`) and the «No leídos» / «Menciones» toggles.
        $filters = TeamThreadListQuery::normalize($validated);

        // «Mesa de control»: a requester's chat is her desk thread, created
        // the first time she opens the chat; an auditor gets the desk section.
        $isRequester = $this->desk->isRequester($viewer);
        $attendsDesk = $this->desk->attends($viewer);
        $deskTab = is_string($validated['desk'] ?? null) ? $validated['desk'] : null;
        $deskThread = $isRequester
            ? $this->desk->threadFor($viewer)
            // An auditor (or admin) may also write to the desk herself.
            : TeamThread::query()->where('desk_requester_id', $viewer->id)->first();

        $active = null;
        $messages = null;

        if ($thread !== null) {
            $thread->load(['activeParticipations' => fn ($participants) => $participants->orderBy('joined_at'), 'activeParticipations.user:id,name,is_active']);
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
            // Numbers of the quick filters («No leídos», «Menciones») in the scope.
            'threadCounts' => fn (): array => $this->list->counts($viewer, $filters['filter']),
            'can' => [
                'createGroup' => $viewer->can('createGroup', TeamThread::class),
                'startDirect' => $viewer->can('startDirect', TeamThread::class),
                // «Exportar» of the desk section (DeskRequestsReport).
                'exportDesk' => $attendsDesk && $viewer->holdsPermission(Permission::ExportReports),
            ],
            // null: no desk for this person. A requester only has her desk
            // thread; an auditor gets the section with its tabs (`desk` in
            // the URL opens it on that tab).
            'desk' => match (true) {
                $isRequester => ['role' => 'requester', 'thread_id' => $deskThread?->id, 'tab' => null],
                $attendsDesk => ['role' => 'auditor', 'thread_id' => $deskThread?->id, 'tab' => $deskTab],
                default => null,
            },
            'deskInbox' => fn (): ?array => $attendsDesk && $deskTab !== null ? $this->deskInbox->handle($viewer, $deskTab) : null,
            'deskPending' => fn (): ?int => $attendsDesk ? $this->deskInbox->pendingCount($viewer) : null,
            'limits' => [
                'messageMaxLength' => (int) config('team-chat.message_max_length'),
                'maxFiles' => (int) config('team-chat.attachments.max_files'),
                'maxKilobytes' => (int) config('team-chat.attachments.max_kilobytes'),
                // HEIC/HEIF are accepted by the server but left out of the picker:
                // an iPhone then hands over its photos as JPEG, which every
                // browser shows in the chat.
                'accept' => implode(',', array_map(fn (string $extension): string => '.'.$extension, array_values(array_diff(array_merge(...array_values($types)), ['heic', 'heif'])))),
                'groupMaxParticipants' => (int) config('team-chat.group_max_participants'),
                'maxThreads' => TeamThreadListQuery::MAX_LIMIT,
                'maxMentions' => (int) config('team-chat.max_mentions'),
                'reactions' => array_values(array_map('strval', (array) config('team-chat.reactions'))),
                // This server's PHP upload limits (may be lower than maxKilobytes).
                'server' => app(ServerUploadLimits::class)->describe(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function threadDetail(TeamThread $thread, User $viewer, bool $canPost, ?string $postBlockedReason): array
    {
        $me = $thread->participationOf($viewer);
        $partner = $thread->isDirect() ? $this->presenter->partner($thread, $viewer) : null;
        $isDeskRequester = $thread->isDesk() && $thread->desk_requester_id === $viewer->id;
        $canAttendDesk = Gate::allows('attendDesk', $thread);

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
            // A requester never sees which auditors hold her desk thread.
            'participants' => array_values($thread->activeParticipations->filter(fn (TeamParticipant $participant): bool => ! $isDeskRequester || $participant->user_id === $viewer->id)->map(fn (TeamParticipant $participant): array => [
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
                // «Mesa de control»: «Aceptar solicitud» / «Solicitud completa».
                'acceptDesk' => $canAttendDesk && $thread->desk_status === TeamDeskStatus::Pending,
                'completeDesk' => $canAttendDesk && $thread->isDeskAssignedTo($viewer),
            ],
            'post_blocked_reason' => $canPost ? null : $postBlockedReason,
            'desk' => $this->presenter->desk($thread, $viewer),
            // Notes and tags of the request: only for whoever attends the desk.
            'desk_details' => $thread->isDesk() && $canAttendDesk ? $this->presenter->deskDetails($thread, $viewer) : null,
            // The viewer's own chat colors for this thread (never anyone else's).
            'appearance' => $this->appearances->forViewer($viewer, $thread),
        ];
    }
}
