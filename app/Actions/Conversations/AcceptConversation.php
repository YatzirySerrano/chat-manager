<?php

namespace App\Actions\Conversations;

use App\Enums\AgentAvailability;
use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Exceptions\ConversationConflict;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Conversations\ConversationAccess;
use App\Services\Conversations\ConversationLifecycle;
use Illuminate\Support\Facades\DB;

/**
 * An agent takes a pending conversation from a queue they can accept from.
 * The conversation row is locked, so when two agents accept at once the
 * second one finds it already taken and receives a controlled conflict.
 *
 * Only an agent declared available takes new conversations. Availability is
 * read from the locked, freshly read user, so switching to unavailable while
 * an accept waits for the lock is honored when it proceeds.
 */
class AcceptConversation
{
    public function __construct(
        private ConversationAccess $access,
        private ConversationLifecycle $lifecycle,
    ) {}

    public function handle(Conversation $conversation, User $actor): Conversation
    {
        return DB::transaction(function () use ($conversation, $actor): Conversation {
            $conversation = $this->lifecycle->lock($conversation);

            if ($conversation->status === ConversationStatus::Open && $conversation->assigned_user_id === $actor->id) {
                return $conversation;
            }

            if ($conversation->status !== ConversationStatus::Pending || $conversation->assigned_user_id !== null) {
                throw $conversation->status === ConversationStatus::Closed
                    ? new ConversationConflict('La conversación ya fue cerrada.')
                    : ConversationConflict::alreadyTaken();
            }

            $department = $this->lifecycle->lockDepartment($conversation->department_id);
            $actor = $this->lifecycle->freshUser($actor->id);
            $this->lifecycle->authorize($actor, 'accept', $conversation);

            if ($actor->availability !== AgentAvailability::Available) {
                throw ConversationConflict::unavailableToAccept();
            }

            $limit = $this->access->reachedLimit($actor, $department, lockingRead: true);

            if ($limit !== null) {
                throw new ConversationConflict("Ya atiendes el máximo de {$limit} conversaciones permitido. Cierra o libera alguna antes de aceptar otra.");
            }

            $this->lifecycle->apply($conversation, ConversationStatus::Open, $this->lifecycle->attendedBy($actor));
            $this->lifecycle->touchAssignment($actor);
            $this->lifecycle->record($conversation, ConversationEventType::Accepted, $actor, [
                'to_user_id' => $actor->id,
                'to_department_id' => $conversation->department_id,
            ]);

            return $conversation;
        });
    }
}
