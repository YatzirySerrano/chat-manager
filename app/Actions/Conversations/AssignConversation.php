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

/**
 * A user who can assign makes an agent responsible for the conversation,
 * keeping its department. The agent must be able to attend it: active,
 * allowed to reply and reaching the department. Assigning by hand ignores
 * availability and the self-service limit on purpose: it is a decision of
 * someone with authority.
 */
class AssignConversation
{
    public function __construct(
        private ConversationAccess $access,
        private ConversationLifecycle $lifecycle,
    ) {}

    public function handle(Conversation $conversation, User $agent, User $actor, ?int $expectedVersion = null): Conversation
    {
        return DB::transaction(function () use ($conversation, $agent, $actor, $expectedVersion): Conversation {
            $conversation = $this->lifecycle->lock($conversation, $expectedVersion);
            $actor = $this->lifecycle->authorize($actor, 'assign', $conversation);

            if ($conversation->status === ConversationStatus::Open && $conversation->assigned_user_id === $agent->id) {
                throw new ConversationConflict("La conversación ya está asignada a {$agent->name}.");
            }

            $agent = $this->lifecycle->freshUser($agent->id);
            $refusal = $this->access->attendRefusal($agent, $conversation->department()->first());

            if ($refusal !== null) {
                throw new ConversationConflict($refusal);
            }

            $previous = $conversation->assigned_user_id;

            $this->lifecycle->apply($conversation, ConversationStatus::Open, $this->lifecycle->attendedBy($agent));
            $this->lifecycle->touchAssignment($agent);
            $this->lifecycle->record($conversation, ConversationEventType::Assigned, $actor, [
                'from_user_id' => $previous,
                'to_user_id' => $agent->id,
                'to_department_id' => $conversation->department_id,
            ]);

            return $conversation;
        });
    }
}
