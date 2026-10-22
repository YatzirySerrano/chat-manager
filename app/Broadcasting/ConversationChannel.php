<?php

namespace App\Broadcasting;

use App\Models\User;

/**
 * Authorizes "conversations.{conversationId}": whoever can view the
 * conversation now (ConversationAccess on a fresh user).
 */
class ConversationChannel
{
    public function __construct(private RealtimeAudience $audience) {}

    public function join(User $user, string $conversationId): bool
    {
        $id = RealtimeChannels::identifier($conversationId);
        $fresh = $this->audience->freshUser($user);

        if ($id === null || $fresh === null) {
            return false;
        }

        return $this->audience->canJoinConversation($fresh, $id);
    }
}
