<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Exceptions\ConversationConflict;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Conversations\ConversationAccess;
use App\Services\Conversations\ConversationLifecycle;
use App\Services\Conversations\PendingAttention;
use App\Services\Conversations\QueueRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * An agent takes a pending conversation from a queue they can accept from.
 * The conversation row is locked, so when two agents accept at once the
 * second one finds it already taken and receives a controlled conflict.
 *
 * Eligibility (active account and role, permission, department membership,
 * department active), the capacity limit and the control of pending
 * conversations (PendingAttention: no new conversation while customers wait
 * too long for this agent's answer) are read from the locked, freshly read
 * user; there is no manual availability to check. The user row lock
 * serializes two tabs (or retried requests) of the same agent, and the
 * pending conversations are read with a locking read, past the snapshot.
 *
 * Validation order, once permission (403) and state (409) passed, each
 * refusal with its own explanation and code:
 *
 * 1. completeness, 2. minimum wait, 3. chronological order (QueueRules,
 *    codes needs_info, min_wait and queue_order);
 * 4. the capacity limit; 5. the control of pending conversations.
 *
 * Whoever holds conversations.override-queue-rules may justify an exception
 * to rules 1 to 3 only (`$overrideReason`): the bypassed rules and the
 * reason are recorded in the event and in the audit log. Capacity and
 * pending conversations are never bypassed here.
 *
 * The queue rules are evaluated right after the conversation is locked and
 * before the department and agent locks: the earlier requests the order
 * rule reads under lock are conversation rows, the first step of the
 * engine's lock order (QueueRules::earlierEligible()).
 */
class AcceptConversation
{
    public function __construct(
        private ConversationAccess $access,
        private ConversationLifecycle $lifecycle,
        private PendingAttention $pendingAttention,
        private QueueRules $queueRules,
    ) {}

    public function handle(Conversation $conversation, User $actor, ?string $overrideReason = null): Conversation
    {
        return DB::transaction(function () use ($conversation, $actor, $overrideReason): Conversation {
            $conversation = $this->lifecycle->lock($conversation);

            if ($conversation->status === ConversationStatus::Open && $conversation->assigned_user_id === $actor->id) {
                return $conversation;
            }

            // Permission first (403), then the state (409: closed or taken by
            // another agent). The user is read without locks here, so the
            // lock order below is kept.
            $this->lifecycle->authorize($actor, 'accept', $conversation);

            $violations = $this->queueRules->violations($conversation, lockingRead: true);

            $department = $this->lifecycle->lockDepartment($conversation->department_id);
            $actor = $this->lifecycle->freshUser($actor->id);

            // Again with the user row locked: a permission revoked meanwhile is honored.
            if (! Gate::forUser($actor)->allows('accept', $conversation)) {
                throw ConversationLifecycle::forbidden($conversation);
            }

            // With the actor row locked, so an exception permission revoked
            // meanwhile is honored too.
            $override = $this->queueRules->enforce($violations, $actor, $overrideReason);

            $limit = $this->access->reachedLimit($actor, $department, lockingRead: true);

            if ($limit !== null) {
                throw new ConversationConflict(QueueRules::capacityMessage($limit));
            }

            if ($this->pendingAttention->blocks($actor, lockingRead: true)) {
                throw new ConversationConflict(PendingAttention::BLOCKED_MESSAGE);
            }

            $this->lifecycle->apply($conversation, ConversationStatus::Open, $this->lifecycle->attendedBy($actor));
            $this->lifecycle->touchAssignment($actor);
            $this->lifecycle->record($conversation, ConversationEventType::Accepted, $actor, [
                'to_user_id' => $actor->id,
                'to_department_id' => $conversation->department_id,
                'metadata' => $override === null ? null : ['queue_override' => $override],
            ]);

            if ($override !== null) {
                $this->queueRules->recordOverride($conversation, $actor, $override, 'accepted');
            }

            return $conversation;
        });
    }
}
