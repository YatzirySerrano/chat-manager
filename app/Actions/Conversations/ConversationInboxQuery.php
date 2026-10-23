<?php

namespace App\Actions\Conversations;

use App\Actions\Contacts\ContactSearch;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\Permission;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Conversations\ConversationAccess;
use App\Support\FullTextTerms;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The conversations inbox as the conversations screen lists it. Shared by
 * the screen and the conversations report, so both always agree.
 *
 * The inbox depends on the actor's reach, never on role names:
 * - personal (scope limited to their own assignments): "mine" (assigned
 *   to them) and, with the accept permission, "queue" (pending
 *   conversations of their departments waiting to be accepted);
 * - team (departments or global scope): "all" authorized conversations
 *   with every filter, and "mine" when they also attend conversations.
 * A view or filter outside the actor's options is ignored, so tampering
 * with the query string never widens what they see.
 *
 * The search finds the contact (ContactSearch: phone, email or name) or,
 * when asked, the content of the WhatsApp messages (FULLTEXT); both only
 * narrow the conversations the actor may see, closed ones included only
 * with the history permission.
 *
 * The list is paged with a keyset cursor over its order (status priority,
 * last activity, id), so loading more never repeats or skips a row and has
 * no upper bound.
 *
 * Unread messages are personal (unreadMessages()): the customer messages
 * after the viewer's read marker. Without a marker, only the ones nobody
 * answered yet count (after the latest reply of a person), and closed
 * conversations never count, so whoever never opened a conversation does
 * not inherit its whole history as unread (docs/bandejas-y-asignacion.md).
 *
 * @phpstan-type InboxFilters array{view: string, views: non-empty-list<string>, personal: bool, status: string, department: string|null, agent: string|null, connection: int|null, search: string, search_in?: string, tag?: int|null, sort?: string, unread?: bool}
 * @phpstan-type InboxCursor array{rank: int, at: string|null, id: int}
 */
class ConversationInboxQuery
{
    /**
     * Filter value meaning "without department" or "without agent".
     */
    public const NONE = 'none';

    /**
     * Inbox views: every authorized conversation, the actor's own ones, and
     * the pending ones waiting to be accepted in the actor's departments.
     */
    public const VIEW_ALL = 'all';

    public const VIEW_MINE = 'mine';

    public const VIEW_QUEUE = 'queue';

    /**
     * Orderings of the list: pending first then latest activity (default),
     * latest activity, or longest waiting first.
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
     * Statuses in the order of the priority sort.
     *
     * @var list<string>
     */
    private const PRIORITY = ['pending', 'open', 'on_hold', 'closed'];

    /**
     * Position of the status in PRIORITY (1 = pending).
     */
    private const PRIORITY_RANK = "FIELD(conversations.status, 'pending', 'open', 'on_hold', 'closed')";

    public function __construct(private ConversationAccess $access, private ContactSearch $contacts) {}

    /**
     * Validation rules of the inbox filters (query string).
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'view' => ['nullable', Rule::in([self::VIEW_ALL, self::VIEW_MINE, self::VIEW_QUEUE])],
            'status' => ['nullable', Rule::in(['active', 'all', ...array_column(ConversationStatus::cases(), 'value')])],
            'department' => ['nullable', 'regex:/^(none|\d{1,10})$/'],
            'agent' => ['nullable', 'regex:/^(none|\d{1,10})$/'],
            'connection' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'search_in' => ['nullable', Rule::in([self::SEARCH_CONTACT, self::SEARCH_MESSAGES])],
            'tag' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', Rule::in([self::SORT_PRIORITY, self::SORT_RECENT, self::SORT_OLDEST])],
            'unread' => ['nullable', 'boolean'],
        ];
    }

    /**
     * The filters the inbox applies for the actor, from validated input.
     *
     * @param  array<string, mixed>  $validated
     * @return InboxFilters
     */
    public function filters(User $actor, array $validated): array
    {
        $personal = $this->access->hasPersonalInbox($actor);
        $views = $this->views($actor, $personal);
        $view = in_array($validated['view'] ?? null, $views, true) ? (string) $validated['view'] : $views[0];
        $department = $validated['department'] ?? null;
        $agent = $validated['agent'] ?? null;
        $search = $validated['search'] ?? null;
        $status = $validated['status'] ?? null;

        return [
            'view' => $view,
            'views' => $views,
            'personal' => $personal,
            // The queue only holds pending conversations: status tabs do not apply.
            // "Mine" never holds pending ones (accepting assigns, queuing unassigns),
            // so its hidden "Pending" tab falls back to the active conversations.
            'status' => $view === self::VIEW_QUEUE || ($view === self::VIEW_MINE && $status === ConversationStatus::Pending->value)
                ? 'active'
                : (is_string($status) ? $status : 'active'),
            'department' => is_scalar($department) && (string) $department !== '' ? (string) $department : null,
            // Personal inboxes never filter by agent: they only hold their own work.
            'agent' => ! $personal && is_scalar($agent) && (string) $agent !== '' ? (string) $agent : null,
            'connection' => is_numeric($validated['connection'] ?? null) ? (int) $validated['connection'] : null,
            'search' => trim(is_string($search) ? $search : ''),
            'search_in' => ($validated['search_in'] ?? null) === self::SEARCH_MESSAGES ? self::SEARCH_MESSAGES : self::SEARCH_CONTACT,
            'tag' => is_numeric($validated['tag'] ?? null) ? (int) $validated['tag'] : null,
            'sort' => in_array($validated['sort'] ?? null, [self::SORT_RECENT, self::SORT_OLDEST], true) ? (string) $validated['sort'] : self::SORT_PRIORITY,
            'unread' => filter_var($validated['unread'] ?? false, FILTER_VALIDATE_BOOL),
        ];
    }

    /**
     * Visible conversations of a view with every filter except the status
     * tab, unordered (for counters).
     *
     * @param  InboxFilters  $filters
     * @return Builder<Conversation>
     */
    public function filtered(User $actor, array $filters, ?string $view = null): Builder
    {
        $department = $filters['department'];
        $agent = $filters['agent'];
        $search = $filters['search'];

        return Conversation::query()
            ->visibleTo($actor)
            ->tap(fn (Builder $query) => $this->restrictToView($query, $view ?? $filters['view'], $actor))
            ->when($department === self::NONE, fn (Builder $query) => $query->whereNull('conversations.department_id'))
            ->when(ctype_digit((string) $department), fn (Builder $query) => $query->where('conversations.department_id', (int) $department))
            ->when($agent === self::NONE, fn (Builder $query) => $query->whereNull('conversations.assigned_user_id'))
            ->when(ctype_digit((string) $agent), fn (Builder $query) => $query->where('conversations.assigned_user_id', (int) $agent))
            ->when($filters['connection'] !== null, fn (Builder $query) => $query->where('conversations.connection_id', $filters['connection']))
            ->when(($filters['tag'] ?? null) !== null, fn (Builder $query) => $query->whereHas('tags', fn (Builder $tags) => $tags->whereKey($filters['tag'] ?? 0)))
            ->when($search !== '', fn (Builder $query) => $this->constrainToSearch($query, $search, $filters['search_in'] ?? self::SEARCH_CONTACT))
            ->when($filters['unread'] ?? false, fn (Builder $query) => $query
                // Closed conversations never count as unread: filtering them on
                // the outer row lets the status index skip them before the
                // correlated lookup runs for each candidate.
                ->where('conversations.status', '<>', ConversationStatus::Closed->value)
                ->whereExists(fn (QueryBuilder $unread) => $this->unreadMessages($unread->selectRaw('1'), $actor)));
    }

    /**
     * The inbox list: the filtered view narrowed to the status tab, pending
     * first, then by latest activity.
     *
     * @param  InboxFilters  $filters
     * @return Builder<Conversation>
     */
    public function handle(User $actor, array $filters): Builder
    {
        $status = $filters['status'];
        $sort = $filters['sort'] ?? self::SORT_PRIORITY;

        return $this->filtered($actor, $filters)
            ->when($status === 'active', fn (Builder $query) => $query->notClosed())
            ->when(! in_array($status, ['active', 'all'], true), fn (Builder $query) => $query->where('conversations.status', $status))
            ->when($sort === self::SORT_PRIORITY, fn (Builder $query) => $query->orderByRaw(self::PRIORITY_RANK))
            ->when($sort === self::SORT_OLDEST, fn (Builder $query) => $query->orderBy('conversations.last_message_at')->orderBy('conversations.id'))
            ->when($sort !== self::SORT_OLDEST, fn (Builder $query) => $query->orderByDesc('conversations.last_message_at')->orderByDesc('conversations.id'));
    }

    /**
     * Narrows the list to the rows that follow the cursor in its order.
     * Conversations without activity yet (no last_message_at) go last in
     * the descending orders and first in the "oldest" one, as MariaDB sorts
     * NULL values.
     *
     * @param  Builder<Conversation>  $query
     * @param  InboxCursor  $cursor
     * @return Builder<Conversation>
     */
    public function startingAfter(Builder $query, array $cursor, string $sort): Builder
    {
        ['rank' => $rank, 'at' => $at, 'id' => $id] = $cursor;

        if ($sort === self::SORT_OLDEST) {
            return $query->where(fn (Builder $after) => $at === null
                ? $after->where(fn (Builder $same) => $same->whereNull('conversations.last_message_at')->where('conversations.id', '>', $id))
                    ->orWhereNotNull('conversations.last_message_at')
                : $after->where('conversations.last_message_at', '>', $at)
                    ->orWhere(fn (Builder $same) => $same->where('conversations.last_message_at', $at)->where('conversations.id', '>', $id)));
        }

        $laterInTime = fn (Builder $after) => $at === null
            ? $after->whereNull('conversations.last_message_at')->where('conversations.id', '<', $id)
            : $after->where(fn (Builder $older) => $older
                ->where('conversations.last_message_at', '<', $at)
                ->orWhere(fn (Builder $same) => $same->where('conversations.last_message_at', $at)->where('conversations.id', '<', $id))
                ->orWhereNull('conversations.last_message_at'));

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
    public function cursorFor(Conversation $row): array
    {
        $at = $row->getRawOriginal('last_message_at');

        return [
            'rank' => (int) array_search($row->status->value, self::PRIORITY, true) + 1,
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
        if ($personal) {
            return $actor->holdsPermission(Permission::AcceptConversations)
                ? [self::VIEW_MINE, self::VIEW_QUEUE]
                : [self::VIEW_MINE];
        }

        return $this->access->attendsConversations($actor)
            ? [self::VIEW_ALL, self::VIEW_MINE]
            : [self::VIEW_ALL];
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
     * Narrows the conversations to the search: the contact (ContactSearch),
     * or the content of their WhatsApp messages through the FULLTEXT index.
     * Both are uncorrelated subqueries, so the index is searched once and
     * the actor's reach (applied by the caller) still decides what is seen.
     * A message search without any searchable word finds nothing
     * (searchIgnored()).
     *
     * @param  Builder<Conversation>  $query
     */
    private function constrainToSearch(Builder $query, string $search, string $searchIn): void
    {
        if ($searchIn !== self::SEARCH_MESSAGES) {
            $query->whereIn('conversations.contact_id', Contact::query()
                ->select('contacts.id')
                ->tap(fn (Builder $contacts) => $this->contacts->constrain($contacts, $search)));

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
     * Narrows the visible conversations to the chosen view. The queue is not
     * access to colleagues' work: only pending, unassigned conversations of
     * the departments the actor may accept from.
     *
     * @param  Builder<Conversation>  $query
     */
    private function restrictToView(Builder $query, string $view, User $actor): void
    {
        match ($view) {
            self::VIEW_MINE => $query->where('conversations.assigned_user_id', $actor->id),
            self::VIEW_QUEUE => $query
                ->where('conversations.status', ConversationStatus::Pending)
                ->whereNull('conversations.assigned_user_id')
                ->whereIn('conversations.department_id', $this->access->acceptableDepartmentIds($actor)),
            default => null,
        };
    }
}
