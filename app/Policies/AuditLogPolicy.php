<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

class AuditLogPolicy
{
    /**
     * Roles with a narrower scope only see the operations they performed themselves.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->holdsPermission(Permission::ViewAuditLog);
    }
}
