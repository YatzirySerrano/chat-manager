<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationStatus;
use App\Enums\Permission;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Conversations\ConversationAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

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
 * @phpstan-type InboxFilters array{view: string, views: non-empty-list<string>, personal: bool, status: string, department: string|null, agent: string|null, connection: int|null, search: string}
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

    public function __construct(private ConversationAccess $access) {}

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
            'status' => $view === self::VIEW_QUEUE ? 'active' : (is_string($status) ? $status : 'active'),
            'department' => is_scalar($department) && (string) $department !== '' ? (string) $department : null,
            // Personal inboxes never filter by agent: they only hold their own work.
            'agent' => ! $personal && is_scalar($agent) && (string) $agent !== '' ? (string) $agent : null,
            'connection' => is_numeric($validated['connection'] ?? null) ? (int) $validated['connection'] : null,
            'search' => trim(is_string($search) ? $search : ''),
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
            ->when($search !== '', fn (Builder $query) => $query->whereHas('contact', fn (Builder $contact) => $contact
                ->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('profile_name', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('phone_e164', 'like', '%'.addcslashes(preg_replace('/\D+/', '', $search) ?: $search, '%_\\').'%')));
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

        return $this->filtered($actor, $filters)
            ->when($status === 'active', fn (Builder $query) => $query->notClosed())
            ->when(! in_array($status, ['active', 'all'], true), fn (Builder $query) => $query->where('conversations.status', $status))
            ->orderByRaw("FIELD(conversations.status, 'pending', 'open', 'on_hold', 'closed')")
            ->orderByDesc('conversations.last_message_at')
            ->orderByDesc('conversations.id');
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
