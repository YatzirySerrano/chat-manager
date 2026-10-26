<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Conversations\ConversationInboxQuery;
use App\Enums\ConnectionChannel;
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
use App\Services\Conversations\ContactConversationHistory;
use App\Services\Conversations\ConversationAccess;
use App\Services\Conversations\ConversationTimeline;
use App\Services\Conversations\CustomerServiceWindow;
use App\Services\Exports\ReportExporter;
use App\Services\Personalization\ChatAppearances;
use App\Services\PlatformSettings;
use App\Support\Attachments\ChannelAttachmentPolicy;
use App\Support\Personalization\ViewerAlias;
use App\Support\ProtectedAccounts;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\JsonResponse;
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
 * @phpstan-import-type InboxCursor from ConversationInboxQuery
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

    /**
     * Rows one request of the list endpoint returns at most: the inbox
     * refreshes the rows it loaded beyond the first page with it. There is
     * no bound on how far the list can be followed.
     */
    public const LIST_CHUNK_MAX = 500;

    /** Previous conversations of the contact summarized in the panel. */
    private const CONTACT_HISTORY = 6;

    public function __construct(private ConversationAccess $access) {}

    /**
     * Props of the list (left panel), reloaded when filters change.
     *
     * @var list<string>
     */
    public const LIST_PROPS = ['conversations', 'filters', 'counts', 'inbox', 'unreadCount'];

    /**
     * Props of the selected conversation (chat and details panel).
     *
     * @var list<string>
     */
    public const SELECTION_PROPS = ['selectedId', 'conversation', 'window', 'timeline', 'details', 'contactUrl'];

    /**
     * The list as the user last left it, so going back to it after an
     * operation keeps their filters.
     */
    public static function listUrl(): string
    {
        return route('conversations.index', self::rememberedQuery());
    }

    /**
     * Where accepting a conversation leads: when the list the user left was
     * narrowed to the pending ones (the "Pendientes" chip, or the legacy
     * queue), the accepted row would leave it, so they go to the general
     * inbox with their other filters and the conversation open, where the
     * row stays in place as "En atención". Null keeps the usual way through
     * show().
     */
    public static function listUrlAfterAccepting(Conversation $conversation): ?string
    {
        $query = self::rememberedQuery();

        if (($query['status'] ?? null) !== ConversationStatus::Pending->value) {
            return null;
        }

        unset($query['view'], $query['status']);

        return route('conversations.index', [...$query, 'c' => $conversation->id]);
    }

    /**
     * The list query remembered in the session. One saved before the
     * general inbox existed may still say view=queue: it becomes the general
     * inbox narrowed to the pending ones, so it never comes back as a view.
     *
     * @return array<string, mixed>
     */
    private static function rememberedQuery(): array
    {
        $query = session()->get(self::LIST_QUERY, []);
        $query = is_array($query) ? $query : [];

        if (($query['view'] ?? null) === ConversationInboxQuery::VIEW_QUEUE) {
            unset($query['view']);
            $query['status'] = ConversationStatus::Pending->value;
        }

        return $query;
    }

    /**
     * The inbox, optionally with a conversation open. A conversation the
     * viewer cannot see is treated exactly like one that does not exist.
     */
    public function index(Request $request, ConversationInboxQuery $inbox, ReportExporter $exporter, CustomerServiceWindow $window, ConversationTimeline $timeline, PlatformSettings $settings, ChannelAttachmentPolicy $attachmentPolicy): Response
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
        ]);

        $filters = $inbox->filters($actor, $validated);
        $views = $filters['views'];

        $request->session()->put(self::LIST_QUERY, $this->listQuery($validated, $filters));

        // Read once per request: the filter options and the details panel
        // share them (one Connection query, one Tag catalog query).
        $connections = $this->memoize(fn (): Collection => Connection::query()->visibleTo($actor)->hidingDeletedFrom($actor)->orderBy('name')->get(['id', 'name', 'display_phone_number', 'channel']));
        $tagCatalog = $this->memoize(fn (): array => $this->tagOptions(Tag::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'color', 'is_active'])));

        // Resolved once and only when a selection prop is requested, so list
        // reloads never pay for the open conversation.
        $resolved = false;
        $cached = null;
        $selection = function () use ($validated, $actor, &$resolved, &$cached): ?Conversation {
            if (! $resolved) {
                $resolved = true;
                $candidate = isset($validated['c']) ? Conversation::query()->find((int) $validated['c']) : null;
                $cached = $candidate !== null && $actor->can('view', $candidate) ? $candidate : null;
                $cached?->load(['contact', 'channelConnection.webChatWidget', 'department', 'assignee', 'tags' => fn (BelongsToMany $query) => $query->orderBy('tags.name')]);
                if ($cached !== null) {
                    // The viewer's private alias of the contact (PERSONALIZATION).
                    ViewerAlias::load($cached, $actor);
                }
            }

            return $cached;
        };

        return Inertia::render('conversations/Index', [
            'conversations' => fn (): array => $this->list($inbox, $actor, $filters, $request),
            'filters' => fn (): array => [
                'view' => $filters['view'],
                'status' => $filters['status'],
                'department' => $filters['department'],
                'agent' => $filters['agent'],
                'connection' => $filters['connection'],
                'channel' => $filters['channel'] ?? null,
                'search' => $filters['search'],
                'search_in' => $filters['search_in'] ?? ConversationInboxQuery::SEARCH_CONTACT,
                'search_ignored' => $inbox->searchIgnored($filters),
                'tags' => $filters['tags'] ?? [],
                'tag_match' => $filters['tag_match'] ?? ConversationInboxQuery::TAG_MATCH_ANY,
                'sort' => $filters['sort'] ?? ConversationInboxQuery::SORT_RECENT,
                'unread' => $filters['unread'] ?? false,
            ],
            // Counters of every chip in one aggregate query, with the same
            // filters as the list and always within the actor's reach.
            'counts' => fn (): array => $inbox->counts($actor, $filters),
            // The reach behind the views, so the screen names them for what
            // they hold ("Todas las conversaciones", "Conversaciones de mis
            // departamentos"...).
            'inbox' => fn (): array => [
                'personal' => $filters['personal'],
                'views' => $views,
                'scope' => $actor->dataScope()?->value,
                'supervises' => $this->access->supervisesReach($actor),
            ],
            // Conversations with messages unread for the viewer in the current
            // view, filters and status (never closed ones): what the "Unread"
            // filter would list.
            'unreadCount' => fn (): int => $inbox->handle($actor, [...$filters, 'unread' => true])->reorder()->count(),
            'statuses' => fn (): array => ConversationStatus::options(),
            'departments' => fn () => Department::query()->visibleTo($actor)->hidingDeletedFrom($actor)->orderBy('name')->get(['id', 'name', 'is_active']),
            'agents' => fn () => $filters['personal'] ? [] : User::query()->visibleTo($actor)->withoutProtectedFor($actor)->hidingDeletedFrom($actor)->orderBy('name')->get(['id', 'name', 'is_active']),
            'connections' => fn (): array => array_values($connections()->map(fn (Connection $connection): array => [
                'id' => $connection->id,
                'name' => $connection->name,
                'display_phone_number' => $connection->display_phone_number,
            ])->all()),
            'filterOptions' => fn (): array => [
                'channels' => $this->channelOptions($connections()),
            ],
            'canSeeClosed' => fn (): bool => $this->access->readsClosedConversations($actor),
            'tags' => fn (): array => $actor->holdsPermission(Permission::ViewTags) || $actor->holdsPermission(Permission::AssignTags)
                ? $tagCatalog()
                : [],
            // What the list shows the viewer: operational badges (department,
            // responsible agent, connection) only for whoever supervises or
            // distributes work, or has global reach (DESIGN decision 3).
            'viewer' => fn (): array => [
                'can_supervise' => $actor->holdsPermission(Permission::SuperviseConversations)
                    || $actor->holdsPermission(Permission::AssignConversations)
                    || $actor->dataScope() === DataScope::Global,
            ],
            'canSeeUnrouted' => fn (): bool => $actor->dataScope() === DataScope::Global,
            'canExport' => fn (): bool => $exporter->allowsReport('conversations', $actor),
            'composer' => fn (): array => [
                'body_max' => OutboundMessageRequest::BODY_MAX_LENGTH,
                'caption_max' => 1024,
                'note_max' => ConversationNoteRequest::BODY_MAX_LENGTH,
                // Per channel: the composer applies the one of the open conversation.
                'attachments' => $attachmentPolicy->describeAll(),
            ],

            'selectedId' => fn (): ?int => $selection()?->id,
            'selectionMissing' => fn (): bool => isset($validated['c']) && $selection() === null,
            'conversation' => fn (): ?array => ($selected = $selection()) === null ? null : [
                ...ConversationResource::make($selected)->resolve($request),
                // The viewer's own chat colors only (PERSONALIZATION); never another user's.
                'appearance' => app(ChatAppearances::class)->forViewer($actor, $selected),
            ],
            'window' => fn (): ?array => ($selected = $selection()) === null ? null : $window->for($selected)->toArray(),
            'timeline' => fn (): ?array => ($selected = $selection()) === null ? null : $timeline->page($selected, $request),
            'details' => fn (): ?array => ($selected = $selection()) === null ? null : $this->details($selected, $actor, $request, $tagCatalog),
            'contactUrl' => fn (): ?string => ($selected = $selection()) !== null && $actor->can('view', $selected->contact) ? route('contacts.show', $selected->contact) : null,
            'transferReasonRequired' => fn (): bool => (bool) $settings->get('operations.transfer_reason_required'),
        ]);
    }

    /**
     * A lazy value computed at most once, the first time it is asked for
     * (props that are not requested never pay for it).
     *
     * @template TValue
     *
     * @param  callable(): TValue  $resolve
     * @return callable(): TValue
     */
    private function memoize(callable $resolve): callable
    {
        $cache = [];

        return function () use ($resolve, &$cache): mixed {
            if (! array_key_exists('value', $cache)) {
                $cache['value'] = $resolve();
            }

            return $cache['value'];
        };
    }

    /**
     * Rows of the list as JSON, for "load more" and for refreshing the rows
     * loaded beyond the first page: the rows that follow the `after` cursor
     * (the first ones without it) with the same filters, authorized like
     * the inbox.
     */
    public function page(Request $request, ConversationInboxQuery $inbox): JsonResponse
    {
        Gate::authorize('viewAny', Conversation::class);

        /** @var User $actor */
        $actor = $request->user();
        $actor->loadMissing('departments');

        $validated = $request->validate([
            ...ConversationInboxQuery::rules(),
            'after' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::LIST_CHUNK_MAX],
        ]);

        return response()->json($this->list(
            $inbox,
            $actor,
            $inbox->filters($actor, $validated),
            $request,
            isset($validated['after']) ? ConversationInboxQuery::decodeCursor((string) $validated['after']) : null,
            (int) ($validated['limit'] ?? self::LIST_PAGE),
        ));
    }

    /**
     * Old links to a conversation open it inside the inbox, keeping the
     * list the user last looked at.
     */
    public function show(Request $request, Conversation $conversation): RedirectResponse
    {
        abort_unless(Gate::allows('view', $conversation), 404);

        return redirect()->route('conversations.index', [...self::rememberedQuery(), 'c' => $conversation->id]);
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
            // As normalized: the legacy queue is remembered as the general
            // inbox with status=pending, never as a view.
            'view' => $filters['view'] === $filters['views'][0] ? null : $filters['view'],
            'status' => $filters['status'] === 'active' ? null : $filters['status'],
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * Rows of the list with what each row shows for the viewer: the first
     * page, or the rows following a cursor. `next_cursor` points after the
     * last row returned.
     *
     * @param  InboxFilters  $filters
     * @param  InboxCursor|null  $after
     * @return array{data: list<array<string, mixed>>, has_more: bool, next_cursor: string|null}
     */
    private function list(ConversationInboxQuery $inbox, User $actor, array $filters, Request $request, ?array $after = null, int $limit = self::LIST_PAGE): array
    {
        $sort = $filters['sort'] ?? ConversationInboxQuery::SORT_RECENT;
        $listed = $inbox->handle($actor, $filters);

        if ($after !== null) {
            $inbox->startingAfter($listed, $after, $sort);
        }

        $rows = $inbox->withListColumns($listed, $actor)
            ->with([
                'contact',
                // The web chat's sending state reads its widget (Connection::isConfigured()).
                'channelConnection.webChatWidget',
                'department',
                'assignee',
                'tags' => fn (BelongsToMany $query) => $query->orderBy('tags.name'),
            ])
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);
        $last = $rows->last();

        $this->attachLastMessages($rows);
        // The viewer's private aliases of the page's contacts, in one query (PERSONALIZATION).
        ViewerAlias::load($rows, $actor);
        // Rows visible only through the supervisor's departments history:
        // their passage resolved with one query for the page.
        $this->access->rememberPassages($actor, $rows);

        // The abilities of every row ask which departments the actor may
        // accept from: computed once for the whole page (INB-03).
        $data = $this->access->rememberingAcceptableDepartments(
            $actor,
            fn (): array => array_values(ConversationResource::collection($rows)->resolve($request)),
        );

        return [
            'data' => $data,
            'has_more' => $hasMore,
            'next_cursor' => $last === null ? null : ConversationInboxQuery::encodeCursor($inbox->cursorFor($last)),
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
            : Message::query()->whereKey($ids)->get(['id', 'conversation_id', 'direction', 'type', 'status', 'error_code', 'body', 'metadata', 'sent_at', 'created_at', 'updated_at'])->keyBy('id');

        $rows->each(fn (Conversation $row) => $row->setRelation('lastMessage', $messages->get((int) $row->getAttribute('last_message_id'))));
    }

    /**
     * Channels the actor's visible connections use, in the order of the
     * enum: the channel filter only appears with more than one.
     *
     * @param  Collection<int, Connection>  $connections
     * @return list<array{value: string, label: string}>
     */
    private function channelOptions(Collection $connections): array
    {
        $used = $connections->map(fn (Connection $connection): string => $connection->channel->value)->unique()->all();

        return array_values(array_map(
            fn (ConnectionChannel $channel): array => ['value' => $channel->value, 'label' => $channel->label()],
            array_filter(ConnectionChannel::cases(), fn (ConnectionChannel $channel): bool => in_array($channel->value, $used, true)),
        ));
    }

    /**
     * The details panel: contact, verified origin, tags, previous
     * conversations the viewer may see and the contact's latest notes.
     *
     * @param  callable(): list<array{id: int, name: string, color: string, is_active: bool}>  $tagCatalog
     * @return array<string, mixed>
     */
    private function details(Conversation $conversation, User $actor, Request $request, callable $tagCatalog): array
    {
        $contact = $conversation->contact;
        $contact->loadMissing(['tags' => fn (BelongsToMany $query) => $query->orderBy('tags.name')]);
        $canTag = $actor->can('tag', $conversation);

        $contactHistory = app(ContactConversationHistory::class);
        $history = $contactHistory->query($actor, $contact->id)
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
                // The viewer's private alias (only theirs) and the name shown to them.
                'alias' => $alias = ViewerAlias::of($contact, $actor),
                'display_name' => ViewerAlias::displayName($contact, $alias),
                'email' => $contact->email,
                'first_interaction_at' => $contact->first_interaction_at?->toIso8601String(),
                'last_interaction_at' => $contact->last_interaction_at?->toIso8601String(),
                'tags' => $this->tagOptions($contact->tags),
                // Provisional contacts can be saved into the directory from the panel.
                'is_saved' => $contact->isSaved(),
                'can_save' => ! $contact->isSaved() && $actor->can('saveToDirectory', $contact),
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
                'assignee' => app(ProtectedAccounts::class)->nameFor($previous->assignee, $actor),
                'created_at' => $previous->created_at?->toIso8601String(),
                'closed_at' => $previous->closed_at?->toIso8601String(),
                ...$contactHistory->describe($previous, $actor),
            ])->all()),
            'contactNotes' => $actor->can('manageNotes', $contact)
                ? array_values($contact->notes()->with('author:id,name')->latest('id')->limit(3)->get()->map(fn (ContactNote $note): array => [
                    'id' => $note->id,
                    'body' => $note->body,
                    'author' => app(ProtectedAccounts::class)->nameFor($note->author, $actor),
                    'created_at' => $note->created_at?->toIso8601String(),
                ])->all())
                : null,
            'availableTags' => $canTag ? $tagCatalog() : [],
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
}
