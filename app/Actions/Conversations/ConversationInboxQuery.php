<?php

namespace App\Actions\Conversations;

use App\Actions\Contacts\ContactSearch;
use App\Enums\ConnectionChannel;
use App\Enums\ConversationReadiness;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\ContactAlias;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Conversations\ConversationAccess;
use App\Support\Features;
use App\Support\FullTextTerms;
use App\Support\ProtectedAccounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The conversations inbox as the conversations screen lists it. Shared by
 * the screen and the conversations report, so both always agree.
 *
 * The inbox depends on the actor's reach, never on role names:
 * - whoever supervises a team reach (conversations.supervise with global
 *   or departments scope, ConversationAccess::supervisesReach()) opens on
 *   "all": every conversation of their read reach, whoever attends it, in
 *   any department, connection or status. When they also attend, the
 *   "general" inbox and "mine" stay available as views;
 * - any other user who attends conversations opens on the "general" inbox:
 *   in one query, the pending conversations they may accept and the open
 *   ones they attend (always inside visibleTo). They also get "mine"
 *   (assigned to them) and, with departments or global scope, "all"
 *   authorized conversations;
 * - whoever does not attend only gets "all".
 * "queue" is kept as an alias of the general inbox with status=pending,
 * so old links keep working. A view or filter outside the actor's options
 * is ignored, so tampering with the query string never widens what they
 * see.
 *
 * The search finds the contact (ContactSearch: phone, email or name, or
 * an alias the actor gave it, never another user's) or, when asked, the
 * content of the WhatsApp messages (FULLTEXT); both only
 * narrow the conversations the actor may see, closed ones included only
 * with the history or supervise permission.
 *
 * The list is paged with a keyset cursor over its order (latest activity by
 * default, so accepting a row never moves it; status priority only when
 * asked), so loading more never repeats or skips a row and has no upper
 * bound.
 *
 * "On hold" is retired operationally: no chip, counter or filter offers it
 * and the general inbox no longer lists it. Legacy rows still in that
 * status stay readable in "mine" and "all" (they are not closed) until the
 * retirement command moves them; an old link with status=on_hold falls back
 * to the active conversations.
 *
 * Tags: `tags[]` (up to MAX_TAGS) narrows to conversations carrying ANY of
 * them by default (tag_match=any, OR) or ALL of them (tag_match=all, AND).
 * The legacy single `tag` keeps working and joins the same list. Both are
 * EXISTS lookups on the contact_tag pivot (its unique
 * (conversation_id, tag_id) index), applied inside visibleTo, so a tag never
 * reveals a conversation outside the actor's reach.
 *
 * `status=completed` lists the conversations closed as «Solicitud
 * completada» (`completed_at` set): a narrowing of `closed`, with the
 * same history permission. `awaiting=1` keeps the conversations where the
 * customer wrote last and nobody answered yet (the row's `awaiting_reply`),
 * never closed ones.
 *
 * `status=awaiting_info` lists «Esperando información del cliente»: the
 * requests a person returned for missing information that wait, pending and
 * without agent, for the customer to write again (`awaiting_info_at` set,
 * AWAITING_INFO_SQL), sorted by that moment. They are never part of
 * «Pendientes» (the status filter, the general inbox's pending branch and
 * the `pending` counter leave them out) and get their own counter
 * (`awaiting_info`), for the same people who could take them from that
 * department or, in "all", whoever sees them.
 *
 * `category=<id>` narrows to one request category and
 * `readiness=ready|needs_info` to the preparation state of the request;
 * like every common filter they apply to the list and to its counters.
 *
 * Unread messages are personal (unreadMessages()): the customer messages
 * after the viewer's read marker. Without a marker, only the ones nobody
 * answered yet count (after the latest reply of a person), and closed
 * conversations never count, so whoever never opened a conversation does
 * not inherit its whole history as unread (docs/bandejas-y-asignacion.md).
 *
 * @phpstan-type InboxFilters array{view: string, views: non-empty-list<string>, personal: bool, status: string, department: string|null, agent: string|null, connection: int|null, search: string, search_in?: string, tags?: list<int>, tag_match?: string, sort?: string, unread?: bool, awaiting?: bool, channel?: string|null, space?: string|null, category?: int|null, readiness?: string|null}
 * @phpstan-type InboxCounts array{general: int, mine: int, pending: int, attending: int, awaiting_info: int}
 * @phpstan-type InboxCursor array{rank: int, at: string|null, id: int}
 */
class ConversationInboxQuery
{
    /**
     * Filter value meaning "without department" or "without agent".
     */
    public const NONE = 'none';

    /**
     * Inbox views: the general inbox (pending ones the actor may accept and
     * their open ones), every authorized
     * conversation, the actor's own ones, and the legacy "queue" (an alias
     * of the general inbox with status=pending).
     */
    public const VIEW_GENERAL = 'general';

    public const VIEW_ALL = 'all';

    public const VIEW_MINE = 'mine';

    public const VIEW_QUEUE = 'queue';

    /**
     * Orderings of the list: latest activity (default: the newest request on
     * top), longest waiting first (default of "mine": each agent finishes
     * their oldest request first, defaultSort()), or pending first then
     * latest activity.
     */
    public const SORT_PRIORITY = 'priority';

    public const SORT_RECENT = 'recent';

    public const SORT_OLDEST = 'oldest';

    /**
     * What the search looks in: the contact (default) or the messages.
     */
    public const SEARCH_CONTACT = 'contact';

    public const SEARCH_MESSAGES = 'messages';

    /**
     * How several tags combine: any of them (OR, default) or all of them (AND).
     */
    public const TAG_MATCH_ANY = 'any';

    public const TAG_MATCH_ALL = 'all';

    /** Tags one filter may combine at most. */
    public const MAX_TAGS = 10;

    /**
     * Statuses in the order of the priority sort. "On hold" is retired: a
     * legacy row still in it ranks as an open one (PRIORITY_RANK).
     *
     * @var list<string>
     */
    private const PRIORITY = ['pending', 'open', 'closed'];

    /**
     * Position of the status in PRIORITY (1 = pending), legacy on_hold
     * rows ranking as open ones.
     */
    private const PRIORITY_RANK = "CASE conversations.status WHEN 'pending' THEN 1 WHEN 'closed' THEN 3 ELSE 2 END";

    /**
     * Status filter values the inbox offers. The legacy `on_hold` is still
     * accepted, only so old links fall back to the active conversations
     * (filters()) instead of failing.
     *
     * @var list<string>
     */
    private const STATUS_FILTERS = ['active', 'all', 'pending', 'open', 'closed', self::STATUS_COMPLETED, self::STATUS_AWAITING_INFO, 'on_hold'];

    /**
     * Status filter of the closed conversations closed as «Solicitud
     * completada» (`conversations.completed_at` set).
     */
    public const STATUS_COMPLETED = 'completed';

    /**
     * Status filter of «Esperando información del cliente»: pending requests
     * a person returned for missing information (AWAITING_INFO_SQL).
     */
    public const STATUS_AWAITING_INFO = 'awaiting_info';

    /** SQL of the queue clock of a row (QueueRules::CLOCK), the order of «Pendientes». */
    private const QUEUE_CLOCK = 'COALESCE(conversations.queued_at, conversations.created_at)';

    /**
     * A request waiting for the customer's missing information: pending,
     * without agent, «Necesita información» and stamped by the person who
     * returned it (`awaiting_info_at`). The readiness is checked too, so a
     * stale stamp never hides a request that is ready.
     */
    private const AWAITING_INFO_SQL = "conversations.status = 'pending' AND conversations.assigned_user_id IS NULL AND conversations.readiness = 'needs_info' AND conversations.awaiting_info_at IS NOT NULL";

    /** Its negation, for rows already known to be pending: what «Pendientes» lists. */
    private const NOT_AWAITING_INFO_SQL = "(conversations.awaiting_info_at IS NULL OR conversations.readiness <> 'needs_info' OR conversations.assigned_user_id IS NOT NULL)";

    /** «Mensajes web»: only conversations of the web chat. */
    public const SPACE_WEB = 'web';

    /** «Conversaciones»: every channel but the web chat. */
    public const SPACE_MAIN = 'main';

    public function __construct(private ConversationAccess $access, private ContactSearch $contacts) {}

    /**
     * Validation rules of the inbox filters (query string).
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'view' => ['nullable', Rule::in([self::VIEW_GENERAL, self::VIEW_ALL, self::VIEW_MINE, self::VIEW_QUEUE])],
            'status' => ['nullable', Rule::in(self::STATUS_FILTERS)],
            'department' => ['nullable', 'regex:/^(none|\d{1,10})$/'],
            'agent' => ['nullable', 'regex:/^(none|\d{1,10})$/'],
            'connection' => ['nullable', 'integer'],
            'channel' => ['nullable', Rule::enum(ConnectionChannel::class)],
            // «Mensajes web» (only the web chat) or «Conversaciones» (everything else).
            'space' => ['nullable', Rule::in([self::SPACE_WEB, self::SPACE_MAIN])],
            'search' => ['nullable', 'string', 'max:100'],
            'search_in' => ['nullable', Rule::in([self::SEARCH_CONTACT, self::SEARCH_MESSAGES])],
            'tag' => ['nullable', 'integer', 'min:1'],
            'tags' => ['nullable', 'array', 'max:'.self::MAX_TAGS],
            'tags.*' => ['integer', 'min:1', 'distinct'],
            'tag_match' => ['nullable', Rule::in([self::TAG_MATCH_ANY, self::TAG_MATCH_ALL])],
            'sort' => ['nullable', Rule::in([self::SORT_PRIORITY, self::SORT_RECENT, self::SORT_OLDEST])],
            'unread' => ['nullable', 'boolean'],
            'awaiting' => ['nullable', 'boolean'],
            'category' => ['nullable', 'integer', 'min:1'],
            'readiness' => ['nullable', Rule::enum(ConversationReadiness::class)],
        ];
    }

    /**
     * The filters the inbox applies for the actor, from validated input.
     *
     * @param  array<string, mixed>  $validated
     * @return InboxFilters
     */
    public function filters(User $actor, array $validated, string $defaultStatus = 'active'): array
    {
        $personal = $this->access->hasPersonalInbox($actor);
        $views = $this->views($actor, $personal);
        $requested = $validated['view'] ?? null;
        $status = is_string($validated['status'] ?? null) ? (string) $validated['status'] : $defaultStatus;

        // The legacy queue is the general inbox narrowed to the pending ones.
        if ($requested === self::VIEW_QUEUE) {
            $requested = self::VIEW_GENERAL;
            $status = ConversationStatus::Pending->value;
        }

        $view = in_array($requested, $views, true) ? (string) $requested : $views[0];
        $department = $validated['department'] ?? null;
        $agent = $validated['agent'] ?? null;
        $search = $validated['search'] ?? null;
        $channel = $validated['channel'] ?? null;

        // "Mine" never holds pending ones (accepting assigns, queuing
        // unassigns), closed ones need the history or supervise permission
        // (readsClosedConversations()) and "on hold"
        // is retired: all of them fall back to the active conversations
        // instead of an always empty (or legacy) list.
        if (($view === self::VIEW_MINE && in_array($status, [ConversationStatus::Pending->value, self::STATUS_AWAITING_INFO], true))
            || $status === ConversationStatus::OnHold->value
            || (in_array($status, [ConversationStatus::Closed->value, self::STATUS_COMPLETED], true) && ! $this->access->readsClosedConversations($actor))) {
            $status = 'active';
        }

        return [
            'view' => $view,
            'views' => $views,
            'personal' => $personal,
            'status' => $status,
            // «Mensajes web» lists the whole web chat, whatever the department.
            'department' => ($validated['space'] ?? null) !== self::SPACE_WEB && is_scalar($department) && (string) $department !== '' ? (string) $department : null,
            // Personal inboxes never filter by agent: they only hold their own work.
            'agent' => ! $personal && is_scalar($agent) && (string) $agent !== '' ? (string) $agent : null,
            'connection' => is_numeric($validated['connection'] ?? null) ? (int) $validated['connection'] : null,
            'search' => trim(is_string($search) ? $search : ''),
            'search_in' => ($validated['search_in'] ?? null) === self::SEARCH_MESSAGES ? self::SEARCH_MESSAGES : self::SEARCH_CONTACT,
            'tags' => $this->tagIds($validated),
            'tag_match' => ($validated['tag_match'] ?? null) === self::TAG_MATCH_ALL ? self::TAG_MATCH_ALL : self::TAG_MATCH_ANY,
            'sort' => in_array($validated['sort'] ?? null, [self::SORT_PRIORITY, self::SORT_RECENT, self::SORT_OLDEST], true) ? (string) $validated['sort'] : self::defaultSort($view, $status),
            'unread' => filter_var($validated['unread'] ?? false, FILTER_VALIDATE_BOOL),
            'awaiting' => filter_var($validated['awaiting'] ?? false, FILTER_VALIDATE_BOOL),
            'channel' => $channel instanceof ConnectionChannel ? $channel->value : (is_string($channel) && ConnectionChannel::tryFrom($channel) !== null ? $channel : null),
            'category' => is_numeric($validated['category'] ?? null) ? (int) $validated['category'] : null,
            'space' => in_array($validated['space'] ?? null, [self::SPACE_WEB, self::SPACE_MAIN], true) ? (string) $validated['space'] : null,
            // Preparation state: a phase-two filter, ignored while it is off (App\Support\Features).
            'readiness' => ! Features::phaseTwo() ? null : (($readiness = $validated['readiness'] ?? null) instanceof ConversationReadiness
                ? $readiness->value
                : (is_string($readiness) && ConversationReadiness::tryFrom($readiness) !== null ? $readiness : null)),
        ];
    }

    /**
     * The order a view opens with: the newest request on top, except in
     * "mine", where each agent works their own ones oldest first (the one
     * waiting the longest is the next to finish), and in «Pendientes» and
     * «Esperando información», where the request that arrived first is on
     * top and the newer ones go below it (orderColumn()).
     */
    public static function defaultSort(string $view, ?string $status = null): string
    {
        return $view === self::VIEW_MINE || in_array($status, [ConversationStatus::Pending->value, self::STATUS_AWAITING_INFO], true)
            ? self::SORT_OLDEST
            : self::SORT_RECENT;
    }

    /**
     * Tags asked for: `tags[]` plus the legacy single `tag`, without
     * repetitions and capped at MAX_TAGS.
     *
     * @param  array<string, mixed>  $validated
     * @return list<int>
     */
    private function tagIds(array $validated): array
    {
        $requested = is_array($validated['tags'] ?? null) ? $validated['tags'] : [];

        if (is_numeric($validated['tag'] ?? null)) {
            $requested[] = $validated['tag'];
        }

        $ids = [];

        foreach ($requested as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $ids[(int) $id] = true;
            }
        }

        return array_slice(array_keys($ids), 0, self::MAX_TAGS);
    }

    /**
     * Visible conversations of the view (narrowed by the status where the
     * view depends on it) with every common filter, unordered.
     *
     * @param  InboxFilters  $filters
     * @return Builder<Conversation>
     */
    public function filtered(User $actor, array $filters, ?string $view = null): Builder
    {
        return $this->common($actor, $filters)
            ->tap(fn (Builder $query) => $this->restrictToView($query, $view ?? $filters['view'], $actor, $filters['status']));
    }

    /**
     * Counters of the inbox chips in one aggregate query over the visible
     * conversations with the common filters (never the chip itself), so
     * every number is exactly what its chip lists with the same filters:
     * - general: the default view (first chip): the general inbox (pending
     *   ones the actor may accept and their open ones) or, for whoever opens
     *   on "all" (supervisors, users who do not attend), every active
     *   visible conversation;
     * - mine: the active conversations assigned to the actor (legacy rows
     *   on hold included, as the "mine" list shows them);
     * - pending and attending: the branches of the default view (for "all",
     *   every visible pending or open conversation); pending never counts
     *   the requests waiting for the customer's missing information;
     * - awaiting_info: those requests («Esperando información»), in the
     *   departments the actor may accept from (for "all", every visible one).
     * There is no "on hold" counter: that status is retired. Closed
     * conversations are not counted: the history is large and its chip
     * shows no number.
     *
     * @param  InboxFilters  $filters
     * @return InboxCounts
     */
    public function counts(User $actor, array $filters): array
    {
        $active = [ConversationStatus::Pending->value, ConversationStatus::Open->value, ConversationStatus::OnHold->value];
        $mine = ['conversations.assigned_user_id = ?', [$actor->id]];

        if ($filters['views'][0] === self::VIEW_GENERAL) {
            $pending = $this->pendingBranchSql($actor);
            $awaitingInfo = $this->pendingBranchSql($actor, awaitingInfo: true);
            $attending = ['conversations.status = ? AND conversations.assigned_user_id = ?', [ConversationStatus::Open->value, $actor->id]];
            $general = ["({$pending[0]}) OR ({$attending[0]})", [...$pending[1], ...$attending[1]]];
        } else {
            $pending = ['conversations.status = ? AND '.self::NOT_AWAITING_INFO_SQL, [ConversationStatus::Pending->value]];
            $awaitingInfo = [self::AWAITING_INFO_SQL, []];
            $attending = ['conversations.status = ?', [ConversationStatus::Open->value]];
            $general = ['1 = 1', []];
        }

        $columns = ['general' => $general, 'mine' => $mine, 'pending' => $pending, 'attending' => $attending, 'awaiting_info' => $awaitingInfo];
        $sql = [];
        $bindings = [];

        foreach ($columns as $alias => [$condition, $values]) {
            $sql[] = "COALESCE(SUM(CASE WHEN {$condition} THEN 1 ELSE 0 END), 0) AS {$alias}";
            $bindings = [...$bindings, ...$values];
        }

        $row = (array) $this->common($actor, $filters)
            // Only active conversations are counted: the status index skips
            // the closed history before anything is summed.
            ->whereIn('conversations.status', $active)
            ->toBase()
            ->selectRaw(implode(', ', $sql), $bindings)
            ->first();

        return [
            'general' => (int) ($row['general'] ?? 0),
            'mine' => (int) ($row['mine'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
            'attending' => (int) ($row['attending'] ?? 0),
            'awaiting_info' => (int) ($row['awaiting_info'] ?? 0),
        ];
    }

    /**
     * Visible conversations with every filter except the view and the
     * status: department, agent, connection, channel, tag, search and
     * "unread". The actor's reach (visibleTo) always comes first.
     *
     * @param  InboxFilters  $filters
     * @return Builder<Conversation>
     */
    private function common(User $actor, array $filters): Builder
    {
        $department = $filters['department'];
        $agent = $filters['agent'];
        $search = $filters['search'];
        $channel = $filters['channel'] ?? null;
        $space = $filters['space'] ?? null;

        return Conversation::query()
            ->visibleTo($actor)
            // History archived as read-only (Whaticket import) never reaches
            // the inbox, its counters, the search or the conversations
            // export; it is only shown by the archived-history screen.
            ->notHistoryOnly()
            ->when($department === self::NONE, fn (Builder $query) => $query->whereNull('conversations.department_id'))
            ->when(ctype_digit((string) $department), fn (Builder $query) => $query->where('conversations.department_id', (int) $department))
            ->when($agent === self::NONE, fn (Builder $query) => $query->whereNull('conversations.assigned_user_id'))
            // A protected account (root role) is never a valid agent filter
            // for anyone else: its id matches nothing, as an unknown id would.
            ->when(ctype_digit((string) $agent), fn (Builder $query) => $query->where('conversations.assigned_user_id', (int) $agent)
                ->when(app(ProtectedAccounts::class)->hides((int) $agent, $actor), fn (Builder $hidden) => $hidden->whereRaw('1 = 0')))
            ->when($filters['connection'] !== null, fn (Builder $query) => $query->where('conversations.connection_id', $filters['connection']))
            // Uncorrelated: the connections of the channel are read once,
            // deleted ones included (their history keeps its channel).
            ->when($channel !== null, fn (Builder $query) => $query->whereIn('conversations.connection_id', Connection::query()->select('connections.id')->where('connections.channel', $channel)))
            // Two separate inboxes: «Mensajes web» holds only the web chat, «Conversaciones» the rest.
            ->when($space === self::SPACE_WEB, fn (Builder $query) => $query->whereIn('conversations.connection_id', Connection::query()->select('connections.id')->where('connections.channel', ConnectionChannel::WebChat->value)))
            ->when($space === self::SPACE_MAIN, fn (Builder $query) => $query->whereNotIn('conversations.connection_id', Connection::query()->select('connections.id')->where('connections.channel', ConnectionChannel::WebChat->value)))
            // Request type and preparation state (`category`, `readiness`).
            ->when(($filters['category'] ?? null) !== null, fn (Builder $query) => $query->where('conversations.category_id', $filters['category'] ?? null))
            ->when(($filters['readiness'] ?? null) !== null, fn (Builder $query) => $query->where('conversations.readiness', $filters['readiness'] ?? null))
            ->when(($filters['tags'] ?? []) !== [], fn (Builder $query) => $this->constrainToTags($query, $filters['tags'] ?? [], $filters['tag_match'] ?? self::TAG_MATCH_ANY))
            ->when($search !== '', fn (Builder $query) => $this->constrainToSearch($query, $actor, $search, $filters['search_in'] ?? self::SEARCH_CONTACT))
            ->when($filters['unread'] ?? false, fn (Builder $query) => $query
                // Closed conversations never count as unread: filtering them on
                // the outer row lets the status index skip them before the
                // correlated lookup runs for each candidate.
                ->where('conversations.status', '<>', ConversationStatus::Closed->value)
                ->whereExists(fn (QueryBuilder $unread) => $this->unreadMessages($unread->selectRaw('1'), $actor)))
            // The customer wrote last and nobody answered yet: the same rule as
            // the row's `awaiting_reply` (ConversationResource), so the filter
            // lists exactly the rows that show the dot.
            ->when($filters['awaiting'] ?? false, fn (Builder $query) => $query
                ->where('conversations.status', '<>', ConversationStatus::Closed->value)
                ->whereNotNull('conversations.last_inbound_at')
                ->where(fn (Builder $unanswered) => $unanswered
                    ->whereNull('conversations.last_outbound_at')
                    ->orWhereColumn('conversations.last_inbound_at', '>', 'conversations.last_outbound_at')));
    }

    /**
     * The inbox list: the filtered view narrowed to the status, by latest
     * activity (or pending first, or longest waiting, when asked).
     *
     * @param  InboxFilters  $filters
     * @return Builder<Conversation>
     */
    public function handle(User $actor, array $filters): Builder
    {
        $status = $filters['status'];
        $sort = $filters['sort'] ?? self::SORT_OLDEST;
        $column = self::orderColumn($status);

        return $this->filtered($actor, $filters)
            ->when($status === 'active', fn (Builder $query) => $query->notClosed())
            ->when($status === self::STATUS_COMPLETED, fn (Builder $query) => $query->where('conversations.status', ConversationStatus::Closed->value)->whereNotNull('conversations.completed_at'))
            // «Pendientes» never lists the requests waiting for the customer's
            // missing information; «Esperando información» lists only them.
            ->when($status === ConversationStatus::Pending->value, fn (Builder $query) => $query->where('conversations.status', $status)->whereRaw(self::NOT_AWAITING_INFO_SQL))
            ->when($status === self::STATUS_AWAITING_INFO, fn (Builder $query) => $query->whereRaw(self::AWAITING_INFO_SQL))
            ->when(! in_array($status, ['active', 'all', self::STATUS_COMPLETED, self::STATUS_AWAITING_INFO, ConversationStatus::Pending->value], true), fn (Builder $query) => $query->where('conversations.status', $status))
            ->when($sort === self::SORT_PRIORITY, fn (Builder $query) => $query->orderByRaw(self::PRIORITY_RANK))
            ->when($sort === self::SORT_OLDEST, fn (Builder $query) => $query->orderByRaw($column)->orderBy('conversations.id'))
            ->when($sort !== self::SORT_OLDEST, fn (Builder $query) => $query->orderByRaw($column.' DESC')->orderByDesc('conversations.id'));
    }

    /**
     * The moment the list is ordered (and paged) by, as SQL: the latest
     * activity; in «Esperando información», since when the request waits for
     * the customer; in «Pendientes», the queue clock (QueueRules::CLOCK: when
     * the request entered the queue, never null), so the order of the list
     * is the order of arrival and a request that came back from «Esperando
     * información» takes its place behind the ones already waiting. The
     * customer writing again in a waiting request never moves it up.
     *
     * @return literal-string
     */
    private static function orderColumn(?string $status): string
    {
        return match ($status) {
            self::STATUS_AWAITING_INFO => 'conversations.awaiting_info_at',
            ConversationStatus::Pending->value => self::QUEUE_CLOCK,
            default => 'conversations.last_message_at',
        };
    }

    /**
     * Narrows the list to the rows that follow the cursor in its order.
     * Conversations without activity yet (no last_message_at) go last in
     * the descending orders and first in the "oldest" one, as MariaDB sorts
     * NULL values. `$status` picks the ordering moment (orderColumn()).
     *
     * @param  Builder<Conversation>  $query
     * @param  InboxCursor  $cursor
     * @return Builder<Conversation>
     */
    public function startingAfter(Builder $query, array $cursor, string $sort, ?string $status = null): Builder
    {
        ['rank' => $rank, 'at' => $at, 'id' => $id] = $cursor;
        $column = self::orderColumn($status);

        if ($sort === self::SORT_OLDEST) {
            return $query->where(fn (Builder $after) => $at === null
                ? $after->where(fn (Builder $same) => $same->whereRaw("{$column} IS NULL")->where('conversations.id', '>', $id))
                    ->orWhereRaw("{$column} IS NOT NULL")
                : $after->whereRaw("{$column} > ?", [$at])
                    ->orWhere(fn (Builder $same) => $same->whereRaw("{$column} = ?", [$at])->where('conversations.id', '>', $id)));
        }

        $laterInTime = fn (Builder $after) => $at === null
            ? $after->whereRaw("{$column} IS NULL")->where('conversations.id', '<', $id)
            : $after->where(fn (Builder $older) => $older
                ->whereRaw("{$column} < ?", [$at])
                ->orWhere(fn (Builder $same) => $same->whereRaw("{$column} = ?", [$at])->where('conversations.id', '<', $id))
                ->orWhereRaw("{$column} IS NULL"));

        if ($sort === self::SORT_RECENT) {
            return $query->where($laterInTime);
        }

        return $query->where(fn (Builder $after) => $after
            ->whereRaw(self::PRIORITY_RANK.' > ?', [$rank])
            ->orWhere(fn (Builder $sameRank) => $sameRank->whereRaw(self::PRIORITY_RANK.' = ?', [$rank])->where($laterInTime)));
    }

    /**
     * The cursor pointing right after a listed row: the position of its
     * status in the priority order (1 = pending), its raw last activity and
     * its id.
     *
     * @return InboxCursor
     */
    public function cursorFor(Conversation $row, ?string $status = null): array
    {
        $at = match ($status) {
            self::STATUS_AWAITING_INFO => $row->getRawOriginal('awaiting_info_at'),
            // The queue clock (QUEUE_CLOCK), as stored.
            ConversationStatus::Pending->value => $row->getRawOriginal('queued_at') ?? $row->getRawOriginal('created_at'),
            default => $row->getRawOriginal('last_message_at'),
        };

        return [
            'rank' => $row->status === ConversationStatus::OnHold ? 2 : (int) array_search($row->status->value, self::PRIORITY, true) + 1,
            'at' => is_string($at) ? $at : null,
            'id' => (int) $row->id,
        ];
    }

    /**
     * @param  InboxCursor  $cursor
     */
    public static function encodeCursor(array $cursor): string
    {
        return rtrim(strtr(base64_encode((string) json_encode([$cursor['rank'], $cursor['at'], $cursor['id']])), '+/', '-_'), '=');
    }

    /**
     * The cursor sent back by the client. A tampered or stale one is
     * rejected instead of being guessed.
     *
     * @return InboxCursor
     *
     * @throws ValidationException
     */
    public static function decodeCursor(string $encoded, string $key = 'after'): array
    {
        $values = json_decode((string) base64_decode(strtr($encoded, '-_', '+/'), true), true);

        if (! is_array($values) || ! array_is_list($values) || count($values) !== 3
            || ! is_int($values[0]) || $values[0] < 1 || $values[0] > count(self::PRIORITY)
            || ! ($values[1] === null || (is_string($values[1]) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $values[1]) === 1))
            || ! is_int($values[2]) || $values[2] < 1) {
            throw ValidationException::withMessages([$key => 'La lista cambió. Vuelve a cargarla.']);
        }

        return ['rank' => $values[0], 'at' => $values[1], 'id' => $values[2]];
    }

    /**
     * Adds what the inbox list shows per row for the viewer: the id of the
     * latest message (for its preview) and how many of its customer
     * messages are unread for the viewer (unreadMessages()). Both are index
     * backed correlated subqueries, evaluated only for the rows of the page.
     *
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    public function withListColumns(Builder $query, User $viewer): Builder
    {
        return $query
            ->select('conversations.*')
            ->selectSub(fn ($latest) => $latest
                ->from('messages')
                ->selectRaw('MAX(messages.id)')
                ->whereColumn('messages.conversation_id', 'conversations.id'), 'last_message_id')
            ->selectSub(fn (QueryBuilder $unread) => $this->unreadMessages($unread->selectRaw('COUNT(*)'), $viewer), 'unread_count');
    }

    /**
     * Narrows a subquery on `messages`, correlated with the outer
     * `conversations` row, to the customer messages unread for the viewer:
     * - after the viewer's read marker, when they have one (it only moves
     *   forward while the chat is on screen, never when listing);
     * - without a marker, after the latest reply sent by a person
     *   (automatic messages do not count as an answer), so what a colleague
     *   already answered, or imported history, is not new to them;
     * - never in closed conversations: the counter signals pending work.
     * Every lookup is backed by the messages(conversation_id, direction, id)
     * index and the unique read marker per user and conversation.
     */
    private function unreadMessages(QueryBuilder $messages, User $viewer): QueryBuilder
    {
        return $messages
            ->from('messages')
            ->whereColumn('messages.conversation_id', 'conversations.id')
            ->where('messages.direction', MessageDirection::Inbound->value)
            ->whereRaw('conversations.status <> ?', [ConversationStatus::Closed->value])
            ->whereRaw(
                'messages.id > COALESCE('
                .'(SELECT conversation_reads.last_read_message_id FROM conversation_reads WHERE conversation_reads.conversation_id = conversations.id AND conversation_reads.user_id = ?), '
                .'(SELECT answered.id FROM messages AS answered WHERE answered.conversation_id = conversations.id AND answered.direction = ? AND answered.sender_user_id IS NOT NULL ORDER BY answered.id DESC LIMIT 1), '
                .'0)',
                [$viewer->id, MessageDirection::Outbound->value],
            );
    }

    /**
     * Views of the inbox the actor may open; the first one is the default.
     *
     * @return non-empty-list<string>
     */
    public function views(User $actor, bool $personal): array
    {
        if (! $this->access->attendsConversations($actor)) {
            return [self::VIEW_ALL];
        }

        if ($personal) {
            return [self::VIEW_GENERAL, self::VIEW_MINE];
        }

        // Organizational supervision: whoever supervises their (global or
        // departments) reach opens on every conversation of it, whoever
        // attends it; their own work stays one view away.
        return $this->access->supervisesReach($actor)
            ? [self::VIEW_ALL, self::VIEW_GENERAL, self::VIEW_MINE]
            : [self::VIEW_GENERAL, self::VIEW_MINE, self::VIEW_ALL];
    }

    /**
     * Whether the search finds nothing because none of its words can be
     * searched: a message search with only short words or InnoDB stopwords.
     * The inbox tells the person instead of showing a bare empty list.
     *
     * @param  InboxFilters  $filters
     */
    public function searchIgnored(array $filters): bool
    {
        return ($filters['search_in'] ?? self::SEARCH_CONTACT) === self::SEARCH_MESSAGES
            && $filters['search'] !== ''
            && FullTextTerms::booleanPrefixQuery($filters['search']) === null;
    }

    /**
     * Narrows the conversations to the ones carrying any (OR) or all (AND)
     * of the tags. Each lookup is a correlated EXISTS on the pivot alone (no
     * join with `tags`), backed by its unique (contact_id, tag_id)
     * index: "any" is one lookup with IN, "all" one lookup per tag (at most
     * MAX_TAGS), so neither groups nor counts rows.
     *
     * @param  Builder<Conversation>  $query
     * @param  list<int>  $tagIds
     */
    private function constrainToTags(Builder $query, array $tagIds, string $match): void
    {
        $tagged = fn (array $ids) => fn (QueryBuilder $pivot) => $pivot
            ->selectRaw('1')
            ->from('contact_tag')
            ->whereColumn('contact_tag.contact_id', 'conversations.contact_id')
            ->whereIn('contact_tag.tag_id', $ids);

        if ($match !== self::TAG_MATCH_ALL) {
            $query->whereExists($tagged($tagIds));

            return;
        }

        foreach ($tagIds as $tagId) {
            $query->whereExists($tagged([$tagId]));
        }
    }

    /**
     * Narrows the conversations to the search: the contact (ContactSearch),
     * or the content of their WhatsApp messages through the FULLTEXT index.
     * Both are uncorrelated subqueries, so the index is searched once and
     * the actor's reach (applied by the caller) still decides what is seen.
     * The contact search also finds the contacts the actor gave an alias
     * containing the text: only the actor's own aliases (their
     * unique(user_id, contact_id) index), never another user's.
     * A message search without any searchable word finds nothing
     * (searchIgnored()).
     *
     * @param  Builder<Conversation>  $query
     */
    private function constrainToSearch(Builder $query, User $actor, string $search, string $searchIn): void
    {
        if ($searchIn !== self::SEARCH_MESSAGES) {
            $query->where(fn (Builder $match) => $match
                ->whereIn('conversations.contact_id', Contact::query()
                    ->select('contacts.id')
                    ->tap(fn (Builder $contacts) => $this->contacts->constrain($contacts, $search)))
                ->orWhereIn('conversations.contact_id', ContactAlias::query()
                    ->select('contact_aliases.contact_id')
                    ->where('contact_aliases.user_id', $actor->id)
                    ->where('contact_aliases.alias', 'like', '%'.addcslashes($search, '\\%_').'%')));

            return;
        }

        $words = FullTextTerms::booleanPrefixQuery($search);

        if ($words === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn('conversations.id', Message::query()
            ->select('messages.conversation_id')
            ->whereFullText('messages.body', $words, ['mode' => 'boolean']));
    }

    /**
     * Narrows the visible conversations to the chosen view.
     *
     * The general inbox, inside visibleTo and in one query: the pending,
     * unassigned conversations the actor may accept (their acceptable
     * departments and, with global scope, the ones without department, as
     * canAccept()) and the open ones they attend. Its pending branch is not access to colleagues' work. The
     * status IN filter on top lets the status index skip the closed
     * history; closed conversations (asked by the status) are the visible
     * closed ones, outside the predicate.
     *
     * @param  Builder<Conversation>  $query
     */
    private function restrictToView(Builder $query, string $view, User $actor, string $status): void
    {
        if ($view === self::VIEW_MINE) {
            $query->where('conversations.assigned_user_id', $actor->id);

            return;
        }

        if ($view !== self::VIEW_GENERAL) {
            return;
        }

        $closed = ConversationStatus::Closed->value;

        $awaitingInfo = $this->pendingBranchSql($actor, awaitingInfo: true);

        match ($status) {
            $closed, self::STATUS_COMPLETED => $query->where('conversations.status', $closed),
            // «Esperando información»: the same reach as the pending branch.
            self::STATUS_AWAITING_INFO => $query->whereRaw("({$awaitingInfo[0]})", $awaitingInfo[1]),
            'all' => $query->where(fn (Builder $either) => $either
                ->where(fn (Builder $general) => $this->generalPredicate($general, $actor))
                ->orWhere('conversations.status', $closed)),
            default => $query
                ->whereIn('conversations.status', [ConversationStatus::Pending->value, ConversationStatus::Open->value])
                ->where(fn (Builder $general) => $this->generalPredicate($general, $actor)),
        };
    }

    /**
     * The predicate of the general inbox (see restrictToView()).
     *
     * @param  Builder<Conversation>  $query
     */
    private function generalPredicate(Builder $query, User $actor): void
    {
        [$pending, $bindings] = $this->pendingBranchSql($actor);

        $query
            ->whereRaw("({$pending})", $bindings)
            ->orWhere(fn (Builder $attending) => $attending
                ->where('conversations.status', ConversationStatus::Open->value)
                ->where('conversations.assigned_user_id', $actor->id));
    }

    /**
     * SQL of the pending branch of the general inbox, shared by the list and
     * the counters so both always agree: pending, unassigned, in a
     * department the actor may accept from or, for whoever accepts
     * conversations without department, without one. The requests waiting
     * for the customer's missing information are left out; with
     * `$awaitingInfo`, only they are kept («Esperando información», same reach).
     *
     * @return array{0: literal-string, 1: list<int|string>}
     */
    private function pendingBranchSql(User $actor, bool $awaitingInfo = false): array
    {
        $departments = $this->access->acceptableDepartmentIds($actor);
        $reach = $departments === []
            ? ['0 = 1']
            : ['conversations.department_id IN ('.implode(', ', array_fill(0, count($departments), '?')).')'];

        if ($this->access->acceptsUnroutedConversations($actor)) {
            $reach[] = 'conversations.department_id IS NULL';
        }

        return [
            'conversations.status = ? AND conversations.assigned_user_id IS NULL AND '
                .($awaitingInfo ? self::AWAITING_INFO_SQL : self::NOT_AWAITING_INFO_SQL)
                .' AND ('.implode(' OR ', $reach).')',
            [ConversationStatus::Pending->value, ...$departments],
        ];
    }
}
