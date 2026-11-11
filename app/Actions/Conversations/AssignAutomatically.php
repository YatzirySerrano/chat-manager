<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Enums\DistributionMode;
use App\Enums\Permission;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use App\Services\Conversations\ConversationAccess;
use App\Services\Conversations\ConversationLifecycle;
use App\Services\Conversations\PendingAttention;
use App\Services\Conversations\QueueRules;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Automatic distribution of departments configured for it: the pending
 * conversation goes to the eligible member (active account and role, able
 * to attend the department, see ConversationAccess::canAttend()) with the
 * fewest conversations in attention; ties go to whoever waited longest since
 * their last assignment. Agents at their limit (own, department or platform,
 * see ConversationAccess::attentionLimit()), with customers waiting too
 * long for their answer (PendingAttention, unless their role is exempt) or
 * no longer eligible are skipped. There is no manual availability: the users.availability column is
 * not read; when nobody qualifies it stays pending in the queue, visible
 * to whoever manages it, and is offered again when capacity frees up
 * (distributeForAgent(), distributeQueue()). A customer message is never
 * rejected for lack of capacity.
 *
 * Queue rules (QueueRules): a request that still needs information or whose
 * minimum wait has not elapsed is never distributed; it stays in the queue
 * and is offered again when it is confirmed, when its wait ends
 * (conversations:offer-waiting-queues) or when capacity frees up. A queue is
 * walked in the order of its clock (`queued_at`, then id), skipping the
 * requests that are not eligible yet.
 *
 * The department row is locked while choosing, so simultaneous arrivals in
 * the same department are distributed one after the other with up to date
 * loads and never land twice on the same free slot.
 */
class AssignAutomatically
{
    /** Conversations offered at most in one pass over a department's queue. */
    public const QUEUE_PASS = 100;

    public function __construct(
        private ConversationAccess $access,
        private ConversationLifecycle $lifecycle,
        private PendingAttention $pendingAttention,
        private QueueRules $queueRules,
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

        // Incomplete or still inside its minimum wait: it waits in the queue.
        if (! $this->queueRules->isEligible($conversation)) {
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

        if ($this->access->reachedLimit($agent, $department, lockingRead: true) !== null || $this->pendingAttention->blocks($agent, lockingRead: true)) {
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
            ->whereNotIn('users.id', $excludedUserIds)
            ->withPermission(Permission::ReplyConversations)
            ->withCount(['assignedConversations as attention_load' => fn ($query) => $query
                ->whereIn('status', ConversationStatus::attending())])
            ->when($this->pendingAttention->isEnabled(), fn ($query) => $query->withCount([
                'assignedConversations as overdue_load' => fn ($conversations) => $this->pendingAttention->overdue($conversations),
            ]))
            ->get()
            ->reject(fn (User $user): bool => (int) $user->getAttribute('overdue_load') > 0 && $this->pendingAttention->appliesTo($user))
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
     * once the agent may take more: they joined a department, one of their
     * conversations was closed or handed to someone else, or their limit was
     * raised. Must be called outside any transaction (after commit), so each
     * conversation keeps the engine's lock order in its own transaction.
     *
     * @param  list<int>  $excludedConversationIds  Conversations not offered in this pass (e.g. the one the agent just released).
     */
    public function distributeForAgent(User $agent, array $excludedConversationIds = []): int
    {
        $agent = User::query()->find($agent->id);

        if ($agent === null || ! $agent->is_active) {
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
     * distributeQueue() for the department once the current transaction
     * commits (right away outside one), e.g. when one of its requests was
     * confirmed as complete. Failures are reported, never rethrown.
     */
    public function distributeDepartmentAfterCommit(?int $departmentId): void
    {
        if ($departmentId === null) {
            return;
        }

        DB::afterCommit(function () use ($departmentId): void {
            try {
                $department = Department::query()->find($departmentId);

                if ($department !== null) {
                    $this->distributeQueue($department);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /**
     * Offer the queues of every active automatic department again, e.g. when
     * the platform-wide limit was raised or removed or a queue rule changed.
     * Must be called outside any transaction; each department is bounded
     * like distributeQueue() and costs one read when nothing is eligible.
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
     * distributeAllQueues() once the current transaction commits (right
     * away outside one), e.g. when a service rule that decides who can be
     * taken changed. Failures are reported, never rethrown.
     */
    public function distributeAllQueuesAfterCommit(): void
    {
        DB::afterCommit(fn () => rescue(fn (): int => $this->distributeAllQueues()));
    }

    /**
     * Whether a change of service rules (ServiceRules::diff(), or the
     * changes of the platform settings) touches a rule that decides which
     * requests are eligible, so the automatic queues must be offered again.
     *
     * @param  array<string, mixed>  $changes
     */
    public static function rulesMayFreeRequests(array $changes): bool
    {
        return array_key_exists('intake.confirmation_required', $changes) || array_key_exists('queue.min_wait_minutes', $changes);
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
     * Offer the department's waiting conversations to its eligible agents,
     * in the order they entered the ready queue (their clock, `queued_at` or
     * else `created_at`, then id: QueueRules::eligible(), the same read the
     * order rule uses). Bounded: at most QUEUE_PASS conversations per call,
     * each one in its own short transaction (no lock over the whole queue).
     * Only eligible requests are read, so a backlog of incomplete or still
     * waiting ones never keeps the pass from the ones that can be taken;
     * each is judged again under its row lock (taken or changed meanwhile:
     * skipped). The pass stops at the first eligible one nobody can take
     * (no capacity left).
     *
     * @param  list<int>  $excludedConversationIds  Conversations left waiting in this pass.
     */
    public function distributeQueue(Department $department, array $excludedConversationIds = []): int
    {
        if (! $department->is_active || $department->distribution_mode !== DistributionMode::Automatic) {
            return 0;
        }

        $assigned = 0;

        $pending = $this->queueRules->eligible($department->id, self::QUEUE_PASS, exceptIds: $excludedConversationIds);

        foreach ($pending as $candidate) {
            /** @var User|false|null $agent False: skipped, not eligible. */
            $agent = DB::transaction(function () use ($candidate): User|false|null {
                $conversation = Conversation::query()->lockForUpdate()->find($candidate->id);

                if ($conversation === null || ! $this->queueRules->isEligible($conversation)) {
                    return false;
                }

                return $this->handle($conversation);
            });

            if ($agent === false) {
                continue;
            }

            if ($agent === null) {
                break;
            }

            $assigned++;
        }

        return $assigned;
    }
}
