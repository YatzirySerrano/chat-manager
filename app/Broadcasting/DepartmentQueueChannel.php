<?php

namespace App\Broadcasting;

use App\Models\User;

/**
 * Authorizes "inbox.queues.{departmentId}": whoever may accept pending
 * conversations from that department now.
 */
class DepartmentQueueChannel
{
    public function __construct(private RealtimeAudience $audience) {}

    public function join(User $user, string $departmentId): bool
    {
        $id = RealtimeChannels::identifier($departmentId);
        $fresh = $this->audience->freshUser($user);

        if ($id === null || $fresh === null) {
            return false;
        }

        return $this->audience->canJoinDepartmentQueue($fresh, $id);
    }
}
