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

/**
 * Automatic distribution of departments configured for it: the pending
 * conversation goes to the available eligible member with the fewest
 * conversations in attention; ties go to whoever waited longest since their
 * last assignment. Agents at the configured limit, unavailable or no longer
 * eligible are skipped; when nobody qualifies it stays in the queue.
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
        $limit = $this->access->attentionLimit();

        if ($limit !== null && $this->access->attentionLoad($agent, lockingRead: true) >= $limit) {
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
        $limit = $this->access->attentionLimit();

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
            ->filter(fn (User $user): bool => $limit === null || $user->getAttribute('attention_load') < $limit);

        return $candidates
            ->sortBy([
                fn (User $first, User $second): int => $first->getAttribute('attention_load') <=> $second->getAttribute('attention_load'),
                fn (User $first, User $second): int => ($first->last_assigned_at?->getTimestamp() ?? 0) <=> ($second->last_assigned_at?->getTimestamp() ?? 0),
                fn (User $first, User $second): int => $first->id <=> $second->id,
            ])
            ->first();
    }

    /**
     * Offer the department's waiting conversations to its available agents,
     * oldest first (e.g. when an agent becomes available). Each conversation
     * is handled in its own short transaction.
     */
    public function distributeQueue(Department $department): int
    {
        if (! $department->is_active || $department->distribution_mode !== DistributionMode::Automatic) {
            return 0;
        }

        $assigned = 0;

        $pending = Conversation::query()
            ->where('department_id', $department->id)
            ->where('status', ConversationStatus::Pending)
            ->whereNull('assigned_user_id')
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
