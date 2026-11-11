<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Exceptions\ConversationConflict;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Conversations\ConversationAccess;
use App\Services\Conversations\ConversationLifecycle;
use App\Services\Conversations\QueueRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A user who can assign makes an agent responsible for the conversation,
 * keeping its department. The agent must be able to attend it: active,
 * allowed to reply and reaching the department. Assigning by hand may go
 * past the agent's limit: it is a decision of someone with authority, but
 * it is never silent: assigning to an agent who already attends their
 * maximum of conversations must be confirmed explicitly; the exception is
 * recorded in the event and never changes the agent's limit. Once committed, the
 * previous responsible's automatic queues are offered again.
 *
 * Queue rules (QueueRules), only when the conversation is taken from a
 * queue (pending): an incomplete request is never handed over without a
 * justified exception; the minimum wait and the chronological order apply
 * only when the actor assigns it to themselves (that is taking it), not
 * when they distribute work to someone else. Exceptions need
 * conversations.override-queue-rules and a reason, and are recorded.
 */
class AssignConversation
{
    public function __construct(
        private ConversationAccess $access,
        private ConversationLifecycle $lifecycle,
        private AssignAutomatically $assignAutomatically,
        private QueueRules $queueRules,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Conversation $conversation, User $agent, User $actor, ?int $expectedVersion = null, bool $confirmOverCapacity = false, ?string $overrideReason = null): Conversation
    {
        return DB::transaction(function () use ($conversation, $agent, $actor, $expectedVersion, $confirmOverCapacity, $overrideReason): Conversation {
            $conversation = $this->lifecycle->lock($conversation);
            $actor = $this->lifecycle->authorize($actor, 'assign', $conversation, $expectedVersion);

            if ($conversation->status === ConversationStatus::Open && $conversation->assigned_user_id === $agent->id) {
                throw new ConversationConflict("La conversación ya está asignada a {$agent->name}.");
            }

            // Before the department and agent locks (QueueRules::earlierEligible()).
            $violations = $this->queueRules->inQueue($conversation)
                ? $this->queueRules->violations($conversation, taking: $agent->id === $actor->id, lockingRead: true)
                : [];

            $department = $this->lifecycle->lockDepartment($conversation->department_id);
            // With violations, the actor's exception permission decides: it
            // is read from their locked row (ascending ids with the agent's).
            [$agent, $actor] = $this->lifecycle->freshAgentAndActor($agent->id, $actor, lockActor: $violations !== []);
            $refusal = $this->access->attendRefusal($agent, $department);

            if ($refusal !== null) {
                throw new ConversationConflict($refusal);
            }

            $override = $this->queueRules->enforce($violations, $actor, $overrideReason);

            $previous = $conversation->assigned_user_id;
            $limit = $previous === $agent->id ? null : $this->access->reachedLimit($agent, $department, lockingRead: true);

            if ($limit !== null && ! $confirmOverCapacity) {
                throw ValidationException::withMessages(['user_id' => $this->access->overCapacityConfirmation($agent, $limit)]);
            }

            $exceptions = array_filter([
                'assigned_over_limit' => $limit !== null,
                'limit' => $limit,
                'queue_override' => $override,
            ]);

            $this->lifecycle->apply($conversation, ConversationStatus::Open, $this->lifecycle->attendedBy($agent));
            $this->lifecycle->touchAssignment($agent);
            $this->lifecycle->record($conversation, ConversationEventType::Assigned, $actor, [
                'from_user_id' => $previous,
                'to_user_id' => $agent->id,
                'to_department_id' => $conversation->department_id,
                'metadata' => $exceptions === [] ? null : $exceptions,
            ]);

            if ($override !== null) {
                $this->queueRules->recordOverride($conversation, $actor, $override, 'assigned', $agent);
            }

            if ($previous !== null && $previous !== $agent->id) {
                $this->assignAutomatically->distributeForAgentAfterCommit($previous);
            }

            return $conversation;
        });
    }
}
