<?php

namespace App\Actions\Conversations;

use App\Enums\AgentAvailability;
use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Enums\DistributionMode;
use App\Enums\Permission;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use App\Services\Conversations\ConversationAccess;
use App\Services\Conversations\ConversationLifecycle;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Automatic distribution of departments configured for it: the pending
 * conversation goes to the available eligible member with the fewest
 * conversations in attention; ties go to whoever waited longest since their
 * last assignment. Agents at their limit (own, department or platform, see
 * ConversationAccess::attentionLimit()), unavailable or no longer eligible
 * are skipped; when nobody qualifies it stays pending in the queue, visible
 * to whoever manages it, and is offered again when capacity frees up
 * (distributeForAgent(), distributeQueue()). A customer message is never
 * rejected for lack of capacity.
 *
 * The department row is locked while choosing, so simultaneous arrivals in
 * the same department are distributed one after the other with up to date
 * loads and never land twice on the same free slot.
 */
class AssignAutomatically
{
    public function __construct(
        private ConversationAccess $access,
        private ConversationLifecycle $lifecycle,
    ) {}

    /**
     * Must run inside a transaction with the conversation already locked.
     *
     * @param  list<int>  $excludedUserIds  Agents that must not receive it (e.g. the one who just released it).
     */
    public function handle(Conversation $conversation, array $excludedUserIds = []): ?User
    {
        if ($conversation->status !== ConversationStatus::Pending || $conversation->department_id === null) {
            return null;
        }

        // Only automatic departments need the row lock; locking shared or
        // manual ones made every new conversation of the department wait for
        // the previous one under inbound bursts (docs/rendimiento-y-carga.md).
        // The mode is checked again under the lock below.
        $automatic = Department::query()->whereKey($conversation->department_id)->where('distribution_mode', DistributionMode::Automatic)->exists();

        if (! $automatic) {
            return null;
        }

        $department = Department::query()->lockForUpdate()->find($conversation->department_id);

        if ($department === null || ! $department->is_active || $department->distribution_mode !== DistributionMode::Automatic) {
            return null;
        }

        $agent = $this->chooseAgent($department, $excludedUserIds);

        if ($agent === null) {
            return null;
        }

        // The agent row lock serializes this with the agent accepting on their
        // own; the load is read again with a locking read, past the snapshot
        // used to choose, so the limit is never exceeded.
        $agent = $this->lifecycle->freshUser($agent->id);

        if ($this->access->reachedLimit($agent, $department, lockingRead: true) !== null) {
            return null;
        }

        $this->lifecycle->apply($conversation, ConversationStatus::Open, $this->lifecycle->attendedBy($agent));
        $this->lifecycle->touchAssignment($agent);
        $this->lifecycle->record($conversation, ConversationEventType::AutoAssigned, null, [
            'to_user_id' => $agent->id,
            'to_department_id' => $department->id,
            'metadata' => ['strategy' => 'least_load'],
        ]);

        return $agent;
    }

    /**
     * @param  list<int>  $excludedUserIds
     */
    private function chooseAgent(Department $department, array $excludedUserIds): ?User
    {
        $candidates = $department->members()
            ->with(['roles', 'departments'])
            ->where('users.is_active', true)
            ->where('users.availability', AgentAvailability::Available)
            ->whereNotIn('users.id', $excludedUserIds)
            ->withPermission(Permission::ReplyConversations)
            ->withCount(['assignedConversations as attention_load' => fn ($query) => $query
                ->whereIn('status', [ConversationStatus::Open, ConversationStatus::OnHold])])
            ->get()
            ->filter(fn (User $user): bool => $this->access->canAttend($user, $department))
            ->filter(function (User $user) use ($department): bool {
                $limit = $this->access->attentionLimit($user, $department);

                return $limit === null || $user->getAttribute('attention_load') < $limit;
            });

        return $candidates
            ->sortBy([
                fn (User $first, User $second): int => $first->getAttribute('attention_load') <=> $second->getAttribute('attention_load'),
                fn (User $first, User $second): int => ($first->last_assigned_at?->getTimestamp() ?? 0) <=> ($second->last_assigned_at?->getTimestamp() ?? 0),
                fn (User $first, User $second): int => $first->id <=> $second->id,
            ])
            ->first();
    }

    /**
     * Offer the waiting conversations of the agent's automatic departments
     * once the agent may take more: they became available, one of their
     * conversations was closed or handed to someone else, or their limit was
     * raised. Must be called outside any transaction (after commit), so each
     * conversation keeps the engine's lock order in its own transaction.
     *
     * @param  list<int>  $excludedConversationIds  Conversations not offered in this pass (e.g. the one the agent just released).
     */
    public function distributeForAgent(User $agent, array $excludedConversationIds = []): int
    {
        $agent = User::query()->find($agent->id);

        if ($agent === null || ! $agent->is_active || $agent->availability !== AgentAvailability::Available) {
            return 0;
        }

        $assigned = 0;

        foreach ($agent->departments()->active()->where('distribution_mode', DistributionMode::Automatic)->orderBy('departments.id')->get() as $department) {
            $assigned += $this->distributeQueue($department, $excludedConversationIds);
        }

        return $assigned;
    }

    /**
     * distributeForAgent() once the current transaction commits (right away
     * outside one). A failure is reported and leaves the queue waiting for
     * the next opportunity; it never undoes the operation that freed room.
     *
     * @param  list<int>  $excludedConversationIds
     */
    public function distributeForAgentAfterCommit(?int $agentId, array $excludedConversationIds = []): void
    {
        if ($agentId === null) {
            return;
        }

        DB::afterCommit(function () use ($agentId, $excludedConversationIds): void {
            try {
                $agent = User::query()->find($agentId);

                if ($agent !== null) {
                    $this->distributeForAgent($agent, $excludedConversationIds);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /**
     * Offer the queues of every active automatic department again, e.g. when
     * the platform-wide limit was raised or removed. Must be called outside
     * any transaction; each department is bounded like distributeQueue().
     */
    public function distributeAllQueues(): int
    {
        $assigned = 0;

        foreach (Department::query()->active()->where('distribution_mode', DistributionMode::Automatic)->orderBy('id')->get() as $department) {
            $assigned += $this->distributeQueue($department);
        }

        return $assigned;
    }

    /**
     * Whether a capacity limit changing from $old to $new may leave room for
     * more conversations (raised, or removed so the next level applies).
     */
    public static function limitMayFreeRoom(mixed $old, mixed $new): bool
    {
        return $new === null || (is_int($old) && is_int($new) && $new > $old);
    }

    /**
     * Same as limitMayFreeRoom() for an override (agent or department) whose
     * null means "inherit the next level": setting one where none existed may
     * raise the effective limit, so it is treated as possibly freeing room
     * (the redistribution is bounded and does nothing when nobody has room).
     */
    public static function overrideMayFreeRoom(mixed $old, mixed $new): bool
    {
        return $old === null || self::limitMayFreeRoom($old, $new);
    }

    /**
     * Offer the department's waiting conversations to its available agents,
     * oldest first. Bounded: at most 100 conversations per call, each one in
     * its own short transaction (no lock over the whole queue), stopping at
     * the first one nobody can take.
     *
     * @param  list<int>  $excludedConversationIds  Conversations left waiting in this pass.
     */
    public function distributeQueue(Department $department, array $excludedConversationIds = []): int
    {
        if (! $department->is_active || $department->distribution_mode !== DistributionMode::Automatic) {
            return 0;
        }

        $assigned = 0;

        $pending = Conversation::query()
            ->where('department_id', $department->id)
            ->where('status', ConversationStatus::Pending)
            ->whereNull('assigned_user_id')
            ->whereNotIn('id', $excludedConversationIds)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(100)
            ->get(['id']);

        foreach ($pending as $candidate) {
            $agent = DB::transaction(function () use ($candidate): ?User {
                $conversation = Conversation::query()->lockForUpdate()->find($candidate->id);

                return $conversation === null ? null : $this->handle($conversation);
            });

            if ($agent === null) {
                break;
            }

            $assigned++;
        }

        return $assigned;
    }
}
