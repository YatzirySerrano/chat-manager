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
use Illuminate\Support\Facades\Gate;

/**
 * An agent takes a pending conversation from a queue they can accept from.
 * The conversation row is locked, so when two agents accept at once the
 * second one finds it already taken and receives a controlled conflict.
 *
 * Eligibility (active account and role, permission, department membership,
 * department active) and the capacity limit are read from the locked,
 * freshly read user; there is no manual availability to check.
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

            // Permission first (403), then the state (409: closed or taken by
            // another agent). The user is read without locks here, so the
            // lock order below is kept.
            $this->lifecycle->authorize($actor, 'accept', $conversation);

            $department = $this->lifecycle->lockDepartment($conversation->department_id);
            $actor = $this->lifecycle->freshUser($actor->id);

            // Again with the user row locked: a permission revoked meanwhile is honored.
            if (! Gate::forUser($actor)->allows('accept', $conversation)) {
                throw ConversationLifecycle::forbidden($conversation);
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
