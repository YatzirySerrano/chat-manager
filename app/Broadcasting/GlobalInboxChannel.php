<?php

namespace App\Broadcasting;

use App\Models\User;

/**
 * Authorizes "inbox.global": users with a global scope who can view
 * conversations.
 */
class GlobalInboxChannel
{
    public function __construct(private RealtimeAudience $audience) {}

    public function join(User $user): bool
    {
        $fresh = $this->audience->freshUser($user);

        return $fresh !== null && $this->audience->canJoinGlobalInbox($fresh);
    }
}
