<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Exceptions\ConversationConflict;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Conversations\ConversationAccess;
use App\Services\Conversations\ConversationLifecycle;
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
 */
class AssignConversation
{
    public function __construct(
        private ConversationAccess $access,
        private ConversationLifecycle $lifecycle,
        private AssignAutomatically $assignAutomatically,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Conversation $conversation, User $agent, User $actor, ?int $expectedVersion = null, bool $confirmOverCapacity = false): Conversation
    {
        return DB::transaction(function () use ($conversation, $agent, $actor, $expectedVersion, $confirmOverCapacity): Conversation {
            $conversation = $this->lifecycle->lock($conversation, $expectedVersion);
            $actor = $this->lifecycle->authorize($actor, 'assign', $conversation);

            if ($conversation->status === ConversationStatus::Open && $conversation->assigned_user_id === $agent->id) {
                throw new ConversationConflict("La conversación ya está asignada a {$agent->name}.");
            }

            $department = $this->lifecycle->lockDepartment($conversation->department_id);
            $agent = $this->lifecycle->freshUser($agent->id);
            $refusal = $this->access->attendRefusal($agent, $department);

            if ($refusal !== null) {
                throw new ConversationConflict($refusal);
            }

            $previous = $conversation->assigned_user_id;
            $limit = $previous === $agent->id ? null : $this->access->reachedLimit($agent, $department, lockingRead: true);

            if ($limit !== null && ! $confirmOverCapacity) {
                throw ValidationException::withMessages(['user_id' => $this->access->overCapacityConfirmation($agent, $limit)]);
            }

            $exceptions = array_filter([
                'assigned_over_limit' => $limit !== null,
                'limit' => $limit,
            ]);

            $this->lifecycle->apply($conversation, ConversationStatus::Open, $this->lifecycle->attendedBy($agent));
            $this->lifecycle->touchAssignment($agent);
            $this->lifecycle->record($conversation, ConversationEventType::Assigned, $actor, [
                'from_user_id' => $previous,
                'to_user_id' => $agent->id,
                'to_department_id' => $conversation->department_id,
                'metadata' => $exceptions === [] ? null : $exceptions,
            ]);

            if ($previous !== null && $previous !== $agent->id) {
                $this->assignAutomatically->distributeForAgentAfterCommit($previous);
            }

            return $conversation;
        });
    }
}
