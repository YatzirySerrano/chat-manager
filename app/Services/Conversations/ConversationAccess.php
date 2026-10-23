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
use Illuminate\Database\Eloquent\Builder;

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
 */
class ConversationAccess
{
    public function __construct(private PlatformSettings $settings) {}

    public function canView(User $user, Conversation $conversation): bool
    {
        if (! $user->holdsPermission(Permission::ViewConversations)) {
            return false;
        }

        if ($conversation->status === ConversationStatus::Closed && ! $user->holdsPermission(Permission::ViewConversationHistory)) {
            return false;
        }

        if ($conversation->assigned_user_id === $user->id) {
            return true;
        }

        return match ($user->dataScope()) {
            DataScope::Global => true,
            DataScope::Departments => $conversation->department_id !== null && $user->belongsToDepartment($conversation->department_id),
            default => $this->isAcceptableQueueItem($conversation)
                && in_array($conversation->department_id, $this->acceptableDepartmentIds($user), true),
        };
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

        if (! $user->holdsPermission(Permission::ViewConversationHistory)) {
            $query->where('conversations.status', '!=', ConversationStatus::Closed);
        }

        match ($user->dataScope()) {
            DataScope::Global => null,
            DataScope::Departments => $query->where(fn (Builder $visible) => $visible
                ->where('conversations.assigned_user_id', $user->id)
                ->orWhereIn('conversations.department_id', $user->departmentIds())),
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
            return $user->dataScope() === DataScope::Global && $this->canAttendConversations($user);
        }

        return in_array($conversation->department_id, $this->acceptableDepartmentIds($user), true);
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
     * Asked before handing a conversation by hand to an agent declared
     * unavailable (an exception only users who can assign may make).
     */
    public function unavailableConfirmation(User $agent): string
    {
        return "{$agent->name} está como No disponible. Confirma que quieres asignarle la conversación de todas formas; su disponibilidad no cambiará.";
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
