<?php

namespace App\Http\Controllers\Conversations;

use App\Enums\AgentAvailability;
use App\Enums\ConversationStatus;
use App\Enums\DataScope;
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
use App\Services\PlatformSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ConversationController extends Controller
{
    /**
     * Filter value meaning "without department" or "without agent".
     */
    private const NONE = 'none';

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
     * number of conversations per status inside the actor's scope.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Conversation::class);

        /** @var User $actor */
        $actor = $request->user();

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'all', ...array_column(ConversationStatus::cases(), 'value')])],
            'department' => ['nullable', 'regex:/^(none|\d{1,10})$/'],
            'agent' => ['nullable', 'regex:/^(none|\d{1,10})$/'],
            'connection' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'mine' => ['nullable', 'boolean'],
        ]);

        $request->session()->put(self::LIST_QUERY, array_filter([
            ...$filters,
            'page' => $request->integer('page') > 1 ? $request->integer('page') : null,
        ], fn (mixed $value): bool => $value !== null && $value !== ''));

        $status = $filters['status'] ?? 'active';
        $search = trim((string) ($filters['search'] ?? ''));

        $filtered = fn (): Builder => Conversation::query()
            ->visibleTo($actor)
            ->when(($filters['department'] ?? null) === self::NONE, fn (Builder $query) => $query->whereNull('department_id'))
            ->when(ctype_digit((string) ($filters['department'] ?? '')), fn (Builder $query) => $query->where('department_id', (int) $filters['department']))
            ->when(($filters['agent'] ?? null) === self::NONE, fn (Builder $query) => $query->whereNull('assigned_user_id'))
            ->when(ctype_digit((string) ($filters['agent'] ?? '')), fn (Builder $query) => $query->where('assigned_user_id', (int) $filters['agent']))
            ->when(isset($filters['connection']), fn (Builder $query) => $query->where('connection_id', (int) $filters['connection']))
            ->when((bool) ($filters['mine'] ?? false), fn (Builder $query) => $query->where('assigned_user_id', $actor->id))
            ->when($search !== '', fn (Builder $query) => $query->whereHas('contact', fn (Builder $contact) => $contact
                ->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('profile_name', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('phone_e164', 'like', '%'.addcslashes(preg_replace('/\D+/', '', $search) ?: $search, '%_\\').'%')));

        $counts = $filtered()
            ->toBase()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $conversations = $filtered()
            ->with(['contact', 'channelConnection', 'department', 'assignee'])
            ->when($status === 'active', fn (Builder $query) => $query->notClosed())
            ->when(! in_array($status, ['active', 'all'], true), fn (Builder $query) => $query->where('status', $status))
            ->orderByRaw("FIELD(status, 'pending', 'open', 'on_hold', 'closed')")
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('conversations/Index', [
            'conversations' => ConversationResource::collection($conversations),
            'filters' => [
                'status' => $status,
                'department' => $filters['department'] ?? null,
                'agent' => $filters['agent'] ?? null,
                'connection' => isset($filters['connection']) ? (int) $filters['connection'] : null,
                'search' => $search,
                'mine' => (bool) ($filters['mine'] ?? false),
            ],
            'counts' => collect(ConversationStatus::cases())->mapWithKeys(fn (ConversationStatus $case): array => [
                $case->value => (int) ($counts[$case->value] ?? 0),
            ]),
            'statuses' => ConversationStatus::options(),
            'departments' => Department::query()->visibleTo($actor)->orderBy('name')->get(['id', 'name', 'is_active']),
            'agents' => User::query()->visibleTo($actor)->orderBy('name')->get(['id', 'name', 'is_active']),
            'connections' => Connection::query()->visibleTo($actor)->orderBy('name')->get(['id', 'name']),
            'availability' => [
                'value' => $actor->availability->value,
                'label' => $actor->availability->label(),
                'options' => array_map(fn (AgentAvailability $option): array => ['value' => $option->value, 'label' => $option->label()], AgentAvailability::cases()),
            ],
            'canSeeUnrouted' => $actor->dataScope() === DataScope::Global,
        ]);
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
