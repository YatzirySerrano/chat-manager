<?php

namespace App\Broadcasting;

use App\Models\User;

/**
 * Authorizes "inbox.departments.{departmentId}": the team inbox of a
 * department (global scope, or departments scope and member).
 */
class DepartmentInboxChannel
{
    public function __construct(private RealtimeAudience $audience) {}

    public function join(User $user, string $departmentId): bool
    {
        $id = RealtimeChannels::identifier($departmentId);
        $fresh = $this->audience->freshUser($user);

        if ($id === null || $fresh === null) {
            return false;
        }

        return $this->audience->canJoinDepartmentInbox($fresh, $id);
    }
}
