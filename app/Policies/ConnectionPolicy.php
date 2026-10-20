<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Connection;
use App\Models\User;

/**
 * Connections hold company-wide credentials, so both permissions require the
 * organization-wide scope (enforced by User::holdsPermission). Managing
 * implies seeing, so a role with only "manage" is never locked out of the
 * screens it administers.
 */
class ConnectionPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->holdsPermission(Permission::ViewConnections)
            || $actor->holdsPermission(Permission::ManageConnections);
    }

    public function view(User $actor, Connection $connection): bool
    {
        return $this->viewAny($actor);
    }

    public function create(User $actor): bool
    {
        return $actor->holdsPermission(Permission::ManageConnections);
    }

    public function update(User $actor, Connection $connection): bool
    {
        return $actor->holdsPermission(Permission::ManageConnections);
    }

    public function changeStatus(User $actor, Connection $connection): bool
    {
        return $actor->holdsPermission(Permission::ManageConnections);
    }

    public function verify(User $actor, Connection $connection): bool
    {
        return $actor->holdsPermission(Permission::ManageConnections);
    }
}
