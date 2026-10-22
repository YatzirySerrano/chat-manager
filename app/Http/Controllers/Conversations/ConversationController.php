<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Conversations\ConversationInboxQuery;
use App\Enums\AgentAvailability;
use App\Enums\ConversationStatus;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conversations\ConversationNoteRequest;
use App\Http\Requests\Conversations\OutboundMessageRequest;
use App\Http\Resources\ContactAttributionResource;
use App\Http\Resources\ConversationResource;
use App\Models\Connection;
use App\Models\ContactNote;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\Tag;
use App\Models\User;
use App\Services\Conversations\ConversationAccess;
use App\Services\Conversations\ConversationTimeline;
use App\Services\Conversations\CustomerServiceWindow;
use App\Services\Conversations\OutboundAttachmentRules;
use App\Services\Exports\ReportExporter;
use App\Services\PlatformSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The messaging inbox: one page with the conversation list, the chat of the
 * selected conversation (`?c=id`) and its details panel. Selecting another
 * conversation only reloads the chat props; changing filters only reloads
 * the list props. Every prop is computed lazily, so partial reloads never
 * pay for what they did not ask for, and every conversation is authorized
 * again on each request.
 *
 * @phpstan-import-type InboxFilters from ConversationInboxQuery
 */
class ConversationController extends Controller
{
    /**
     * Session key holding the query string (filters, view) of the last list
     * the user looked at.
     */
    private const LIST_QUERY = 'conversations.list_query';

    /** Rows of the list loaded at first, and per "load more". */
    public const LIST_PAGE = 25;

    /** Upper bound of rows the list keeps loaded. */
    public const LIST_MAX = 250;

    /** Previous conversations of the contact summarized in the panel. */
    private const CONTACT_HISTORY = 6;

    /**
     * Props of the list (left panel), reloaded when filters change.
     *
     * @var list<string>
     */
    public const LIST_PROPS = ['conversations', 'filters', 'counts', 'inbox', 'viewCounts'];

    /**
     * Props of the selected conversation (chat and details panel).
     *
     * @var list<string>
     */
    public const SELECTION_PROPS = ['selectedId', 'conversation', 'window', 'timeline', 'details', 'contactUrl', 'availability'];

    /**
     * The list as the user last left it, so going back to it after an
     * operation keeps their filters.
     */
    public static function listUrl(): string
    {
        $query = session()->get(self::LIST_QUERY, []);

        return route('conversations.index', is_array($query) ? $query : []);
    }

    /**
     * The inbox, optionally with a conversation open. A conversation the
     * viewer cannot see is treated exactly like one that does not exist.
     */
    public function index(Request $request, ConversationInboxQuery $inbox, ReportExporter $exporter, ConversationAccess $access, CustomerServiceWindow $window, ConversationTimeline $timeline, PlatformSettings $settings, OutboundAttachmentRules $attachmentRules): Response
    {
        Gate::authorize('viewAny', Conversation::class);

        /** @var User $actor */
        $actor = $request->user();

        // Loaded once: the abilities of every listed row ask for the actor's
        // departments, which would otherwise cost queries per row.
        $actor->loadMissing('departments');

        $validated = $request->validate([
            ...ConversationInboxQuery::rules(),
            'c' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::LIST_MAX],
        ]);

        $filters = $inbox->filters($actor, $validated);
        $views = $filters['views'];
        $limit = min(self::LIST_MAX, max(self::LIST_PAGE, (int) ($validated['limit'] ?? self::LIST_PAGE)));

        $request->session()->put(self::LIST_QUERY, $this->listQuery($validated, $filters));

        // Resolved once and only when a selection prop is requested, so list
        // reloads never pay for the open conversation.
        $resolved = false;
        $cached = null;
        $selection = function () use ($validated, $actor, &$resolved, &$cached): ?Conversation {
            if (! $resolved) {
                $resolved = true;
                $candidate = isset($validated['c']) ? Conversation::query()->find((int) $validated['c']) : null;
                $cached = $candidate !== null && $actor->can('view', $candidate) ? $candidate : null;
                $cached?->load(['contact', 'channelConnection', 'department', 'assignee', 'tags' => fn (BelongsToMany $query) => $query->orderBy('tags.name')]);
            }

            return $cached;
        };

        return Inertia::render('conversations/Index', [
            'conversations' => fn (): array => $this->list($inbox, $actor, $filters, $limit, $request),
            'filters' => fn (): array => [
                'view' => $filters['view'],
                'status' => $filters['status'],
                'department' => $filters['department'],
                'agent' => $filters['agent'],
                'connection' => $filters['connection'],
                'search' => $filters['search'],
                'tag' => $filters['tag'] ?? null,
                'sort' => $filters['sort'] ?? ConversationInboxQuery::SORT_PRIORITY,
            ],
            'counts' => fn (): array => $this->statusCounts($inbox, $actor, $filters),
            'inbox' => fn (): array => [
                'personal' => $filters['personal'],
                'views' => $views,
            ],
            // Active conversations per view, with the same filters, so the view
            // switcher tells where work is waiting. Always within the actor's reach.
            'viewCounts' => fn (): array => collect($views)->mapWithKeys(fn (string $option): array => [
                $option => $inbox->filtered($actor, $filters, $option)->notClosed()->count(),
            ])->all(),
            'statuses' => fn (): array => ConversationStatus::options(),
            'departments' => fn () => Department::query()->visibleTo($actor)->hidingDeletedFrom($actor)->orderBy('name')->get(['id', 'name', 'is_active']),
            'agents' => fn () => $filters['personal'] ? [] : User::query()->visibleTo($actor)->hidingDeletedFrom($actor)->orderBy('name')->get(['id', 'name', 'is_active']),
            'connections' => fn () => Connection::query()->visibleTo($actor)->hidingDeletedFrom($actor)->orderBy('name')->get(['id', 'name']),
            'tags' => fn (): array => $actor->holdsPermission(Permission::ViewTags) || $actor->holdsPermission(Permission::AssignTags)
                ? $this->tagOptions(Tag::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'color', 'is_active']))
                : [],
            'canSeeUnrouted' => fn (): bool => $actor->dataScope() === DataScope::Global,
            'canExport' => fn (): bool => $exporter->allowsReport('conversations', $actor),
            'availability' => fn (): array => $this->availability($actor),
            'composer' => fn (): array => [
                'body_max' => OutboundMessageRequest::BODY_MAX_LENGTH,
                'caption_max' => 1024,
                'note_max' => ConversationNoteRequest::BODY_MAX_LENGTH,
                'attachments' => $attachmentRules->describe(),
            ],

            'selectedId' => fn (): ?int => $selection()?->id,
            'selectionMissing' => fn (): bool => isset($validated['c']) && $selection() === null,
            'conversation' => fn (): ?array => ($selected = $selection()) === null ? null : ConversationResource::make($selected)->resolve($request),
            'window' => fn (): ?array => ($selected = $selection()) === null ? null : $window->for($selected)->toArray(),
            'timeline' => fn (): ?array => ($selected = $selection()) === null ? null : $timeline->page($selected, $request),
            'details' => fn (): ?array => ($selected = $selection()) === null ? null : $this->details($selected, $actor, $request),
            'contactUrl' => fn (): ?string => ($selected = $selection()) !== null && $actor->can('view', $selected->contact) ? route('contacts.show', $selected->contact) : null,
            'transferOptions' => Inertia::optional(fn (): array => ($selected = $selection()) === null
                ? ['departments' => [], 'unrouted_agents' => []]
                : $this->transferOptions($selected, $actor, $access)),
            'transferReasonRequired' => fn (): bool => (bool) $settings->get('operations.transfer_reason_required'),
        ]);
    }

    /**
     * Old links to a conversation open it inside the inbox, keeping the
     * list the user last looked at.
     */
    public function show(Request $request, Conversation $conversation): RedirectResponse
    {
        abort_unless(Gate::allows('view', $conversation), 404);

        $query = $request->session()->get(self::LIST_QUERY, []);

        return redirect()->route('conversations.index', [...(is_array($query) ? $query : []), 'c' => $conversation->id]);
    }

    /**
     * The query string remembered for the list: filters only, defaults left out.
     *
     * @param  array<string, mixed>  $validated
     * @param  InboxFilters  $filters
     * @return array<string, mixed>
     */
    private function listQuery(array $validated, array $filters): array
    {
        unset($validated['c'], $validated['limit']);

        if ($filters['personal']) {
            unset($validated['agent']);
        }

        return array_filter([
            ...$validated,
            'view' => $filters['view'] === $filters['views'][0] ? null : $filters['view'],
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * The rows of the list with what each row shows for the viewer.
     *
     * @param  InboxFilters  $filters
     * @return array{data: list<array<string, mixed>>, has_more: bool, limit: int}
     */
    private function list(ConversationInboxQuery $inbox, User $actor, array $filters, int $limit, Request $request): array
    {
        $rows = $inbox->withListColumns($inbox->handle($actor, $filters), $actor)
            ->with([
                'contact',
                'channelConnection',
                'department',
                'assignee',
                'tags' => fn (BelongsToMany $query) => $query->orderBy('tags.name'),
            ])
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        $this->attachLastMessages($rows);

        return [
            'data' => array_values(ConversationResource::collection($rows)->resolve($request)),
            'has_more' => $hasMore,
            'limit' => $limit,
        ];
    }

    /**
     * Loads the latest message of every row with one query.
     *
     * @param  Collection<int, Conversation>  $rows
     */
    private function attachLastMessages(Collection $rows): void
    {
        $ids = $rows->map(fn (Conversation $row): ?int => is_numeric($row->getAttribute('last_message_id')) ? (int) $row->getAttribute('last_message_id') : null)
            ->filter()
            ->values()
            ->all();

        $messages = $ids === []
            ? collect()
            : Message::query()->whereKey($ids)->get(['id', 'conversation_id', 'direction', 'type', 'status', 'body', 'metadata', 'sent_at', 'created_at'])->keyBy('id');

        $rows->each(fn (Conversation $row) => $row->setRelation('lastMessage', $messages->get((int) $row->getAttribute('last_message_id'))));
    }

    /**
     * Conversations per status inside the actor's view and filters.
     *
     * @param  InboxFilters  $filters
     * @return array<string, int>
     */
    private function statusCounts(ConversationInboxQuery $inbox, User $actor, array $filters): array
    {
        $counts = $inbox->filtered($actor, $filters)
            ->toBase()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return collect(ConversationStatus::cases())->mapWithKeys(fn (ConversationStatus $case): array => [
            $case->value => (int) ($counts[$case->value] ?? 0),
        ])->all();
    }

    /**
     * The details panel: contact, verified origin, tags, previous
     * conversations the viewer may see and the contact's latest notes.
     *
     * @return array<string, mixed>
     */
    private function details(Conversation $conversation, User $actor, Request $request): array
    {
        $contact = $conversation->contact;
        $contact->loadMissing(['tags' => fn (BelongsToMany $query) => $query->orderBy('tags.name')]);
        $canTag = $actor->can('tag', $conversation);

        $history = Conversation::query()
            ->visibleTo($actor)
            ->where('contact_id', $contact->id)
            ->whereKeyNot($conversation->id)
            ->with(['department:id,name,color,is_active', 'assignee:id,name'])
            ->latest('id')
            ->limit(self::CONTACT_HISTORY)
            ->get();

        return [
            'contact' => [
                'id' => $contact->id,
                'name' => $contact->name,
                'profile_name' => $contact->profile_name,
                'phone_e164' => $contact->phone_e164,
                'email' => $contact->email,
                'first_interaction_at' => $contact->first_interaction_at?->toIso8601String(),
                'last_interaction_at' => $contact->last_interaction_at?->toIso8601String(),
                'tags' => $this->tagOptions($contact->tags),
            ],
            'origin' => ContactAttributionResource::collection($conversation->attributions()->with('recorder')->orderBy('captured_at')->get())->resolve($request),
            'history' => array_values($history->map(fn (Conversation $previous): array => [
                'id' => $previous->id,
                'status' => $previous->status->value,
                'status_label' => $previous->status->label(),
                'department' => $previous->department === null ? null : [
                    'id' => $previous->department->id,
                    'name' => $previous->department->name,
                    'color' => $previous->department->color->value,
                    'is_active' => $previous->department->is_active,
                ],
                'assignee' => $previous->assignee?->name,
                'created_at' => $previous->created_at?->toIso8601String(),
                'closed_at' => $previous->closed_at?->toIso8601String(),
            ])->all()),
            'contactNotes' => $actor->can('manageNotes', $contact)
                ? array_values($contact->notes()->with('author:id,name')->latest('id')->limit(3)->get()->map(fn (ContactNote $note): array => [
                    'id' => $note->id,
                    'body' => $note->body,
                    'author' => $note->author?->name,
                    'created_at' => $note->created_at?->toIso8601String(),
                ])->all())
                : null,
            'availableTags' => $canTag
                ? $this->tagOptions(Tag::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'color', 'is_active']))
                : [],
            'canExportDossier' => $actor->holdsPermission(Permission::ExportReports),
        ];
    }

    /**
     * @param  iterable<Tag>  $tags
     * @return list<array{id: int, name: string, color: string, is_active: bool}>
     */
    private function tagOptions(iterable $tags): array
    {
        $options = [];

        foreach ($tags as $tag) {
            $options[] = ['id' => $tag->id, 'name' => $tag->name, 'color' => $tag->color->value, 'is_active' => $tag->is_active];
        }

        return $options;
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
