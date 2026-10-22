<?php

namespace App\Broadcasting;

use App\Models\User;

/**
 * Authorizes "users.{userId}": only the user themselves, while the account
 * is active.
 */
class UserChannel
{
    public function __construct(private RealtimeAudience $audience) {}

    public function join(User $user, string $userId): bool
    {
        $id = RealtimeChannels::identifier($userId);
        $fresh = $this->audience->freshUser($user);

        if ($id === null || $fresh === null) {
            return false;
        }

        return $this->audience->canJoinUser($fresh, $id);
    }
}
