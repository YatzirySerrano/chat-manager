<?php

namespace App\Broadcasting;

use App\Models\User;

/**
 * Authorizes "inbox.supervision.{departmentId}": members of the department
 * who supervise it with a departments scope (RealtimeAudience).
 */
class DepartmentSupervisionChannel
{
    public function __construct(private RealtimeAudience $audience) {}

    public function join(User $user, string $departmentId): bool
    {
        $id = RealtimeChannels::identifier($departmentId);
        $fresh = $this->audience->freshUser($user);

        if ($id === null || $fresh === null) {
            return false;
        }

        return $this->audience->canJoinDepartmentSupervision($fresh, $id);
    }
}
