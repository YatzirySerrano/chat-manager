<?php

namespace App\Services\Conversations;

use App\Enums\ConversationStatus;
use App\Enums\DataScope;
use App\Enums\DistributionMode;
use App\Enums\Permission;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use App\Services\PlatformSettings;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use WeakMap;

/**
 * Single source of truth of who reaches which conversation and who may be
 * responsible for one. Every rule combines the four dimensions separately:
 * the action permission, the data scope of the role, department membership
 * and the assignment of the conversation. Role names are never consulted.
 *
 * Visibility:
 * - global scope: every conversation;
 * - departments scope: its departments' conversations plus its own;
 * - assigned scope: its own conversations, plus the pending ones it is
 *   allowed to accept (its active departments that do not distribute
 *   manually). Conversations of colleagues are never visible to it;
 * - closed conversations additionally require the history permission.
 *
 * Supervision (conversations.supervise) is read-only and stays within the
 * data scope: with it, closed conversations are readable without the
 * history permission and, for the departments scope, conversations that
 * passed through one of the supervisor's departments (any event from or to
 * them) stay visible after being transferred elsewhere. It never grants an
 * operation: that widening belongs only to the read reach (canView(),
 * restrictToVisible()); every mutating ability of ConversationPolicy uses the
 * operational reach (canOperate()), which keeps the rules without
 * supervision (own departments, history permission for closed ones).
 * The assigned scope gains nothing from it (its reach is personal).
 */
class ConversationAccess
{
    /**
     * Departments each user may accept from, computed once while a list is
     * being resolved (rememberingAcceptableDepartments()). Keyed by the
     * User instance, so the engine, which reads a fresh user under lock,
     * always computes them again.
     *
     * @var WeakMap<User, list<int>>|null
     */
    private static ?WeakMap $rememberedAcceptable = null;

    /**
     * Whether a conversation passed through the supervisor's departments,
     * remembered per conversation instance and user while it lives (every
     * ability of a listed row asks it again).
     *
     * @var WeakMap<Conversation, array<int, bool>>|null
     */
    private static ?WeakMap $rememberedPassage = null;

    public function __construct(private PlatformSettings $settings) {}

    /**
     * Runs the callback (resolving the abilities of many listed rows) with
     * the departments the user may accept from computed only once. Users
     * with global scope do not depend on a loaded relation, so without it
     * every pending row would ask the database again (docs/rendimiento-y-carga.md).
     * Only for the duration of the callback: nothing outlives the listing.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function rememberingAcceptableDepartments(User $user, Closure $callback): mixed
    {
        self::$rememberedAcceptable ??= new WeakMap;

        if (isset(self::$rememberedAcceptable[$user])) {
            return $callback();
        }

        self::$rememberedAcceptable[$user] = $this->acceptableDepartmentIds($user);

        try {
            return $callback();
        } finally {
            unset(self::$rememberedAcceptable[$user]);
        }
    }

    /**
     * Read reach: whether the user may see the conversation (thread, timeline,
     * attachments, inbox row), including the read-only supervision widening.
     */
    public function canView(User $user, Conversation $conversation): bool
    {
        return $this->reaches($user, $conversation, supervising: true);
    }

    /**
     * Operational reach: the conversations the user may act on (assign,
     * transfer, close, reopen, notes, tags, release). Supervision never
     * widens it: closed conversations require the history permission and,
     * for the departments scope, only the user's departments count.
     */
    public function canOperate(User $user, Conversation $conversation): bool
    {
        return $this->reaches($user, $conversation, supervising: false);
    }

    private function reaches(User $user, Conversation $conversation, bool $supervising): bool
    {
        if (! $user->holdsPermission(Permission::ViewConversations)) {
            return false;
        }

        $readsClosed = $supervising
            ? $this->readsClosedConversations($user)
            : $user->holdsPermission(Permission::ViewConversationHistory);

        if ($conversation->status === ConversationStatus::Closed && ! $readsClosed) {
            return false;
        }

        if ($conversation->assigned_user_id === $user->id) {
            return true;
        }

        return match ($user->dataScope()) {
            DataScope::Global => true,
            DataScope::Departments => ($conversation->department_id !== null && $user->belongsToDepartment($conversation->department_id))
                || ($supervising && $user->holdsPermission(Permission::SuperviseConversations) && $this->passedThroughDepartmentsOf($user, $conversation)),
            default => $this->isAcceptableQueueItem($conversation)
                && in_array($conversation->department_id, $this->acceptableDepartmentIds($user), true),
        };
    }

    /**
     * Resolves, with one query, whether each listed conversation passed
     * through the supervisor's departments, so the read abilities of a page
     * of rows (canView()) never ask the database once per row. Only rows
     * that the passage could make visible are looked up.
     *
     * @param  iterable<Conversation>  $conversations
     */
    public function rememberPassages(User $user, iterable $conversations): void
    {
        if ($user->dataScope() !== DataScope::Departments || ! $user->holdsPermission(Permission::SuperviseConversations)) {
            return;
        }

        $departmentIds = $user->departmentIds();

        if ($departmentIds === []) {
            return;
        }

        self::$rememberedPassage ??= new WeakMap;
        $pending = [];

        foreach ($conversations as $conversation) {
            $outside = $conversation->assigned_user_id !== $user->id
                && ($conversation->department_id === null || ! in_array((int) $conversation->department_id, $departmentIds, true));

            if ($outside && ! array_key_exists($user->id, self::$rememberedPassage[$conversation] ?? [])) {
                $pending[$conversation->id][] = $conversation;
            }
        }

        if ($pending === []) {
            return;
        }

        $passed = array_flip(array_map('intval', DB::table('conversation_events')
            ->whereIn('conversation_id', array_keys($pending))
            ->where(fn (QueryBuilder $passage) => $passage
                ->whereIn('from_department_id', $departmentIds)
                ->orWhereIn('to_department_id', $departmentIds))
            ->distinct()
            ->pluck('conversation_id')
            ->all()));

        foreach ($pending as $id => $instances) {
            foreach ($instances as $conversation) {
                $remembered = self::$rememberedPassage[$conversation] ?? [];
                $remembered[$user->id] = isset($passed[$id]);
                self::$rememberedPassage[$conversation] = $remembered;
            }
        }
    }

    /**
     * Ids of the conversations with an event from or to the departments, as
     * an uncorrelated union of the two indexed lookups.
     *
     * @param  list<int>  $departmentIds
     */
    private function passageConversationIds(array $departmentIds): QueryBuilder
    {
        return DB::query()->fromSub(
            DB::table('conversation_events')->select('conversation_id')->whereIn('from_department_id', $departmentIds)
                ->union(DB::table('conversation_events')->select('conversation_id')->whereIn('to_department_id', $departmentIds)),
            'passages',
        )->select('passages.conversation_id');
    }

    /**
     * Whether the user reads closed conversations (history): with the history
     * permission, or supervising their reach.
     */
    public function readsClosedConversations(User $user): bool
    {
        return $user->holdsPermission(Permission::ViewConversationHistory)
            || $user->holdsPermission(Permission::SuperviseConversations);
    }

    /**
     * Whether any event of the conversation moved it from or to one of the
     * user's departments (it was transferred out of them, or into them and
     * out again).
     */
    private function passedThroughDepartmentsOf(User $user, Conversation $conversation): bool
    {
        $departmentIds = $user->departmentIds();

        if ($departmentIds === []) {
            return false;
        }

        self::$rememberedPassage ??= new WeakMap;
        $remembered = self::$rememberedPassage[$conversation] ?? [];

        if (! array_key_exists($user->id, $remembered)) {
            $remembered[$user->id] = $conversation->events()
                ->where(fn (Builder $passage) => $passage
                    ->whereIn('from_department_id', $departmentIds)
                    ->orWhereIn('to_department_id', $departmentIds))
                ->exists();
            self::$rememberedPassage[$conversation] = $remembered;
        }

        return $remembered[$user->id];
    }

    /**
     * Query counterpart of canView().
     *
     * @param  Builder<Conversation>  $query
     */
    public function restrictToVisible(Builder $query, User $user): void
    {
        if (! $user->holdsPermission(Permission::ViewConversations)) {
            $query->whereRaw('1 = 0');

            return;
        }

        if (! $this->readsClosedConversations($user)) {
            $query->where('conversations.status', '!=', ConversationStatus::Closed);
        }

        $departmentIds = $user->departmentIds();
        $supervises = $user->holdsPermission(Permission::SuperviseConversations) && $departmentIds !== [];

        match ($user->dataScope()) {
            DataScope::Global => null,
            // The passage through the supervisor's departments is an
            // uncorrelated semi-join (union of the from/to department index
            // lookups on conversation_events), not a per-row EXISTS.
            DataScope::Departments => $query->where(fn (Builder $visible) => $visible
                ->where('conversations.assigned_user_id', $user->id)
                ->orWhereIn('conversations.department_id', $departmentIds)
                ->when($supervises, fn (Builder $passed) => $passed->orWhereIn('conversations.id', $this->passageConversationIds($departmentIds)))),
            default => $query->where(fn (Builder $visible) => $visible
                ->where('conversations.assigned_user_id', $user->id)
                ->orWhere(fn (Builder $queue) => $queue
                    ->where('conversations.status', ConversationStatus::Pending)
                    ->whereNull('conversations.assigned_user_id')
                    ->whereIn('conversations.department_id', $this->acceptableDepartmentIds($user)))),
        };
    }

    /**
     * Departments whose pending conversations the user may accept.
     *
     * @return list<int>
     */
    public function acceptableDepartmentIds(User $user): array
    {
        if (self::$rememberedAcceptable !== null && isset(self::$rememberedAcceptable[$user])) {
            return self::$rememberedAcceptable[$user];
        }

        if (! $user->holdsPermission(Permission::AcceptConversations) || ! $this->canAttendConversations($user)) {
            return [];
        }

        // Departments already loaded on the user (once per request by the
        // inbox, or under lock by the engine) answer without one query per
        // conversation listed (docs/rendimiento-y-carga.md). Only when they
        // were loaded with their state: a partial load (`departments:id`)
        // would read every department as inactive.
        if ($user->dataScope() !== DataScope::Global && $user->relationLoaded('departments')
            && $user->departments->every(fn (Department $department): bool => array_key_exists('is_active', $department->getAttributes())
                && array_key_exists('distribution_mode', $department->getAttributes()))) {
            $ids = $user->departments
                ->filter(fn (Department $department): bool => $department->is_active && $department->distribution_mode !== DistributionMode::Manual)
                ->modelKeys();
            $ids = array_map('intval', $ids);
            sort($ids);

            return $ids;
        }

        $query = Department::query()
            ->active()
            ->where('distribution_mode', '!=', DistributionMode::Manual);

        if ($user->dataScope() !== DataScope::Global) {
            $query->whereKey($user->departmentIds());
        }

        return array_values(array_map('intval', $query->pluck('id')->all()));
    }

    /**
     * Whether the user could take the pending conversation from its queue.
     * Conversations without a department are only reachable to global users.
     */
    public function canAccept(User $user, Conversation $conversation): bool
    {
        if (! $this->isAcceptableQueueItem($conversation) || ! $user->holdsPermission(Permission::AcceptConversations)) {
            return false;
        }

        if ($conversation->department_id === null) {
            return $this->acceptsUnroutedConversations($user);
        }

        return in_array($conversation->department_id, $this->acceptableDepartmentIds($user), true);
    }

    /**
     * Whether the user may accept pending conversations without a
     * department: only with the accept permission, global scope and able to
     * attend (canAccept() for a conversation without department, and the
     * pending branch of the general inbox).
     */
    public function acceptsUnroutedConversations(User $user): bool
    {
        return $user->holdsPermission(Permission::AcceptConversations)
            && $user->dataScope() === DataScope::Global
            && $this->canAttendConversations($user);
    }

    /**
     * Whether the user may be responsible for conversations of the
     * department (null meaning "no department"): an active account with an
     * active role that can view and reply, reaching the department.
     */
    public function canAttend(User $user, ?Department $department): bool
    {
        if (! $this->canAttendConversations($user)) {
            return false;
        }

        if ($department === null) {
            return $user->dataScope() === DataScope::Global;
        }

        return $department->is_active
            && ($user->dataScope() === DataScope::Global || $user->belongsToDepartment($department));
    }

    /**
     * Explanation of why the user cannot be responsible, for controlled responses.
     */
    public function attendRefusal(User $user, ?Department $department): ?string
    {
        return match (true) {
            ! $user->is_active => "La cuenta de {$user->name} está eliminada.",
            $user->dataScope() === null => "{$user->name} no tiene un rol activo.",
            ! $this->canAttendConversations($user) => "El rol de {$user->name} no le permite atender conversaciones.",
            $department === null && $user->dataScope() !== DataScope::Global => 'Una conversación sin departamento solo puede asignarse a quien tiene alcance sobre toda la organización.',
            $department !== null && ! $department->is_active => "El departamento {$department->name} está inactivo.",
            $department !== null && ! $this->canAttend($user, $department) => "{$user->name} no pertenece al departamento {$department->name}.",
            default => null,
        };
    }

    /**
     * Whether the user's reach does not go beyond their own assignments (plus
     * the queue they may accept from): their inbox is personal, never a
     * window on colleagues' work. Derived from the data scope, not the role.
     */
    public function hasPersonalInbox(User $user): bool
    {
        return ! in_array($user->dataScope(), [DataScope::Global, DataScope::Departments], true);
    }

    /**
     * Whether the user attends conversations themselves somewhere (can be
     * responsible for at least one department, or for any of them).
     */
    public function attendsConversations(User $user): bool
    {
        return $this->canAttendConversations($user)
            && ($user->dataScope() === DataScope::Global || $user->departmentIds() !== []);
    }

    /**
     * Maximum of conversations in attention of the agent when receiving one of
     * the department, or null when unlimited. Precedence: the agent's own
     * limit, then the department's per agent limit, then the platform-wide
     * limit. The load it is compared with always counts every conversation
     * the agent attends, in any department (attentionLoad()).
     *
     * Self-service (accepting) and automatic distribution never go past it;
     * handing a conversation over by hand past it is an exception reserved to
     * users who can assign, with explicit confirmation (reachedLimit()).
     */
    public function attentionLimit(?User $agent = null, ?Department $department = null): ?int
    {
        foreach ([$agent?->max_open_conversations, $department?->max_open_conversations_per_agent] as $override) {
            if (is_int($override) && $override > 0) {
                return $override;
            }
        }

        $limit = $this->settings->get('operations.max_open_conversations_per_agent');

        return is_int($limit) && $limit > 0 ? $limit : null;
    }

    /**
     * The limit the agent already reached for conversations of the
     * department, or null while they still have room (or have no limit).
     * Inside an engine operation the load is read with a locking read, after
     * the agent row lock, so it includes assignments committed meanwhile.
     */
    public function reachedLimit(User $agent, ?Department $department, bool $lockingRead = false): ?int
    {
        $limit = $this->attentionLimit($agent, $department);

        return $limit !== null && $this->attentionLoad($agent, $lockingRead) >= $limit ? $limit : null;
    }

    /**
     * Asked before handing a conversation by hand to an agent who already
     * attends their maximum (an exception only users who can assign may make).
     */
    public function overCapacityConfirmation(User $agent, int $limit): string
    {
        return "{$agent->name} ya atiende el máximo de {$limit} conversaciones. Confirma que quieres asignársela de todas formas.";
    }

    public function attentionLoad(User $user, bool $lockingRead = false): int
    {
        return Conversation::query()
            ->where('assigned_user_id', $user->id)
            ->attended()
            ->when($lockingRead, fn ($query) => $query->sharedLock())
            ->count();
    }

    private function canAttendConversations(User $user): bool
    {
        return $user->holdsPermission(Permission::ViewConversations)
            && $user->holdsPermission(Permission::ReplyConversations);
    }

    private function isAcceptableQueueItem(Conversation $conversation): bool
    {
        return $conversation->status === ConversationStatus::Pending && $conversation->assigned_user_id === null;
    }
}
