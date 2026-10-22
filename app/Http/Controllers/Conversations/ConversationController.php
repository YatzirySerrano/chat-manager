<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Conversations\ConversationInboxQuery;
use App\Enums\AgentAvailability;
use App\Enums\ConversationStatus;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\ContactAttributionResource;
use App\Http\Resources\ConversationEventResource;
use App\Http\Resources\ConversationMessageResource;
use App\Http\Resources\ConversationNoteResource;
use App\Http\Resources\ConversationResource;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use App\Services\Conversations\ConversationAccess;
use App\Services\Conversations\CustomerServiceWindow;
use App\Services\Exports\ReportExporter;
use App\Services\PlatformSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ConversationController extends Controller
{
    /**
     * Messages shown in the operational view; the advanced inbox pages them.
     */
    private const MESSAGES_SHOWN = 200;

    /**
     * Session key holding the query string (filters, tab, page) of the last
     * queue the user looked at.
     */
    private const LIST_QUERY = 'conversations.list_query';

    /**
     * The queue as the user last left it, so going back to it after an
     * operation or from a conversation keeps their filters.
     */
    public static function listUrl(): string
    {
        $query = session()->get(self::LIST_QUERY, []);

        return route('conversations.index', is_array($query) ? $query : []);
    }

    /**
     * Conversations the actor can see, filtered and paginated, with the
     * number of conversations per status inside the actor's scope. The inbox
     * rules (views by reach, ignored filters) live in ConversationInboxQuery,
     * shared with the conversations report.
     */
    public function index(Request $request, ConversationInboxQuery $inbox, ReportExporter $exporter): Response
    {
        Gate::authorize('viewAny', Conversation::class);

        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate(ConversationInboxQuery::rules());
        $filters = $inbox->filters($actor, $validated);
        $views = $filters['views'];
        $view = $filters['view'];
        $personal = $filters['personal'];

        // Personal inboxes never filter by agent: they only hold their own work.
        if ($personal) {
            unset($validated['agent']);
        }

        $request->session()->put(self::LIST_QUERY, array_filter([
            ...$validated,
            'view' => $view === $views[0] ? null : $view,
            'page' => $request->integer('page') > 1 ? $request->integer('page') : null,
        ], fn (mixed $value): bool => $value !== null && $value !== ''));

        $counts = $inbox->filtered($actor, $filters)
            ->toBase()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $conversations = $inbox->handle($actor, $filters)
            ->with(['contact', 'channelConnection', 'department', 'assignee'])
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('conversations/Index', [
            'conversations' => ConversationResource::collection($conversations),
            'filters' => [
                'view' => $view,
                'status' => $filters['status'],
                'department' => $filters['department'],
                'agent' => $filters['agent'],
                'connection' => $filters['connection'],
                'search' => $filters['search'],
            ],
            'counts' => collect(ConversationStatus::cases())->mapWithKeys(fn (ConversationStatus $case): array => [
                $case->value => (int) ($counts[$case->value] ?? 0),
            ]),
            'inbox' => [
                'personal' => $personal,
                'views' => $views,
            ],
            // Active conversations per view, with the same filters, so the view
            // switcher tells where work is waiting. Always within the actor's reach.
            'viewCounts' => collect($views)->mapWithKeys(fn (string $option): array => [
                $option => $inbox->filtered($actor, $filters, $option)->notClosed()->count(),
            ]),
            'statuses' => ConversationStatus::options(),
            'departments' => Department::query()->visibleTo($actor)->hidingDeletedFrom($actor)->orderBy('name')->get(['id', 'name', 'is_active']),
            'agents' => $personal ? [] : User::query()->visibleTo($actor)->hidingDeletedFrom($actor)->orderBy('name')->get(['id', 'name', 'is_active']),
            'connections' => Connection::query()->visibleTo($actor)->hidingDeletedFrom($actor)->orderBy('name')->get(['id', 'name']),
            'availability' => $this->availability($actor),
            'canSeeUnrouted' => $actor->dataScope() === DataScope::Global,
            'canExport' => $exporter->allowsReport('conversations', $actor),
        ]);
    }

    /**
     * The actor's declared availability and the options to change it.
     *
     * @return array{value: string, label: string, options: list<array{value: string, label: string}>}
     */
    private function availability(User $actor): array
    {
        return [
            'value' => $actor->availability->value,
            'label' => $actor->availability->label(),
            'options' => array_map(fn (AgentAvailability $option): array => ['value' => $option->value, 'label' => $option->label()], AgentAvailability::cases()),
        ];
    }

    /**
     * Operational view of one conversation: its messages, internal notes,
     * customer service window, origin and (with permission) its timeline.
     */
    public function show(Request $request, Conversation $conversation, CustomerServiceWindow $window, ConversationAccess $access, PlatformSettings $settings): Response
    {
        abort_unless(Gate::allows('view', $conversation), 404);

        /** @var User $actor */
        $actor = $request->user();

        $conversation->load(['contact', 'channelConnection', 'department', 'assignee']);

        $messages = $conversation->messages()
            ->with('sender')
            ->orderByDesc('id')
            ->limit(self::MESSAGES_SHOWN)
            ->get()
            ->reverse()
            ->values();

        return Inertia::render('conversations/Show', [
            'conversation' => ConversationResource::make($conversation)->resolve($request),
            'window' => $window->for($conversation)->toArray(),
            'messages' => ConversationMessageResource::collection($messages)->resolve($request),
            'messagesTotal' => $conversation->messages()->count(),
            'notes' => ConversationNoteResource::collection($conversation->notes()->with('author')->orderBy('id')->get())->resolve($request),
            'origin' => ContactAttributionResource::collection($conversation->attributions()->with('recorder')->orderBy('captured_at')->get())->resolve($request),
            'events' => Gate::allows('viewHistory', $conversation)
                ? ConversationEventResource::collection($conversation->events()->with(['actor', 'fromUser', 'toUser', 'fromDepartment', 'toDepartment'])->orderByDesc('id')->limit(100)->get())->resolve($request)
                : null,
            'listUrl' => self::listUrl(),
            'contactUrl' => $actor->can('view', $conversation->contact) ? route('contacts.show', $conversation->contact) : null,
            'transferOptions' => Inertia::optional(fn (): array => $this->transferOptions($conversation, $actor, $access)),
            'transferReasonRequired' => (bool) $settings->get('operations.transfer_reason_required'),
            'availability' => $this->availability($actor),
        ]);
    }

    /**
     * Destinations offered for assigning or transferring: active departments
     * that may receive the connection's conversations, and per department the
     * agents who can attend them. Loaded only when a dialog opens.
     *
     * @return array{departments: list<array{id: int, name: string, agents: list<array{id: int, name: string, availability: string, load: int}>}>, unrouted_agents: list<array{id: int, name: string, availability: string, load: int}>}
     */
    private function transferOptions(Conversation $conversation, User $actor, ConversationAccess $access): array
    {
        if (! $actor->can('assign', $conversation) && ! $actor->can('transfer', $conversation)) {
            return ['departments' => [], 'unrouted_agents' => []];
        }

        $connection = $conversation->channelConnection()->firstOrFail();
        $authorized = $connection->departments()->pluck('departments.id')->all();

        $departments = Department::query()
            ->active()
            ->when($authorized !== [], fn (Builder $query) => $query->whereKey($authorized))
            ->orderBy('name')
            ->get();

        // Only people the actor may already see are offered by name; others
        // can still receive the conversation through the department queue.
        $candidates = User::query()
            ->visibleTo($actor)
            ->with(['roles', 'departments'])
            ->where('is_active', true)
            ->withCount(['assignedConversations as attention_load' => fn (Builder $query) => $query->whereIn('status', [ConversationStatus::Open, ConversationStatus::OnHold])])
            ->orderBy('name')
            ->get();

        $describe = fn (User $user): array => [
            'id' => $user->id,
            'name' => $user->name,
            'availability' => $user->availability->value,
            'load' => (int) $user->getAttribute('attention_load'),
        ];

        return [
            'departments' => array_values($departments->map(fn (Department $department): array => [
                'id' => $department->id,
                'name' => $department->name,
                'agents' => array_values($candidates->filter(fn (User $user): bool => $access->canAttend($user, $department))->map($describe)->all()),
            ])->all()),
            'unrouted_agents' => array_values($candidates->filter(fn (User $user): bool => $access->canAttend($user, null))->map($describe)->all()),
        ];
    }
}
