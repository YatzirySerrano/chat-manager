<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Conversations\ConversationLifecycle;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Returns the non-closed conversations of an agent to their department
 * queue, so no conversation stays assigned to someone who can no longer
 * attend it (account deleted, role changed, removed from the department).
 *
 * It goes through the engine like any other operation: every conversation
 * is locked on its own (in id order, so concurrent releases never deadlock
 * each other), re-checked under the lock, moved with the state matrix and
 * the version counter, and recorded as an "unassigned" event with the
 * department it was in. Once the caller's transaction commits, conversations
 * of automatic departments are offered to another eligible agent, each in
 * its own transaction; manual and shared queues simply wait to be accepted.
 *
 * The agent's conversations are looked up with a locking read, so the
 * decision uses the latest committed assignments even when the caller's
 * transaction snapshot is older (e.g. a role change that waited for an
 * accept in flight to commit).
 *
 * DeleteUser and RemoveDepartmentMember call it while holding the agent's
 * row or membership locks; UpdateUser and SaveRole (after its commit, one
 * transaction per holder) call it without them. Holding them is the one documented exception to the
 * "conversation first" lock order. Should an operation on one of those conversations wait
 * for the same agent row at that moment, MariaDB detects the deadlock and
 * rolls one of the two transactions back completely; no partial state is
 * ever left and the user gets a controlled error to retry.
 */
class ReleaseAssignedConversations
{
    public function __construct(
        private ConversationLifecycle $lifecycle,
        private AssignAutomatically $assignAutomatically,
    ) {}

    /**
     * @return int Number of released conversations.
     */
    public function handle(User $agent, ?int $departmentId = null, ?User $actor = null, string $reason = ''): int
    {
        return DB::transaction(function () use ($agent, $departmentId, $actor, $reason): int {
            $candidateIds = Conversation::query()
                ->where('assigned_user_id', $agent->id)
                ->notClosed()
                ->when($departmentId !== null, fn ($query) => $query->where('department_id', $departmentId))
                ->orderBy('id')
                // A locking read sees the latest committed assignments, not the
                // caller's snapshot: an accept that committed while the caller
                // waited for a lock is released too, never left orphaned.
                ->lockForUpdate()
                ->pluck('id');

            $released = [];

            foreach ($candidateIds as $conversationId) {
                $conversation = $this->lifecycle->lock(new Conversation()->forceFill(['id' => $conversationId]));

                if ($conversation->assigned_user_id !== $agent->id
                    || $conversation->status === ConversationStatus::Closed
                    || ($departmentId !== null && $conversation->department_id !== $departmentId)) {
                    continue;
                }

                $this->lifecycle->apply($conversation, ConversationStatus::Pending, $this->lifecycle->queued());
                $this->lifecycle->record($conversation, ConversationEventType::Unassigned, $actor, [
                    'from_user_id' => $agent->id,
                    'from_department_id' => $conversation->department_id,
                    'note' => trim($reason) !== '' ? trim($reason) : null,
                    'metadata' => ['trigger' => 'access_change'],
                ]);

                $released[] = $conversation->id;
            }

            if ($released !== []) {
                DB::afterCommit(fn () => $this->redistribute($released, $agent->id));
            }

            return count($released);
        });
    }

    /**
     * Offers each released conversation to another agent of an automatic
     * department. A failure leaves that conversation waiting in its queue.
     *
     * @param  list<int>  $conversationIds
     */
    private function redistribute(array $conversationIds, int $previousAgentId): void
    {
        foreach ($conversationIds as $conversationId) {
            try {
                DB::transaction(function () use ($conversationId, $previousAgentId): void {
                    $conversation = $this->lifecycle->lock(new Conversation()->forceFill(['id' => $conversationId]));

                    if ($conversation->status === ConversationStatus::Pending && $conversation->assigned_user_id === null) {
                        $this->assignAutomatically->handle($conversation, [$previousAgentId]);
                    }
                }, attempts: 3);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
