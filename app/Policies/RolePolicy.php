<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PrivilegeGuard;

class RolePolicy
{
    public function __construct(private PrivilegeGuard $guard) {}

    public function viewAny(User $actor): bool
    {
        return $actor->holdsPermission(Permission::ViewRoles);
    }

    public function create(User $actor): bool
    {
        return $actor->holdsPermission(Permission::CreateRoles);
    }

    public function update(User $actor, Role $role): bool
    {
        return ! $role->isDeleted()
            && $actor->holdsPermission(Permission::UpdateRoles)
            && $this->guard->canManageRole($actor, $role);
    }

    /**
     * Logical deletion. The root role can never be deleted.
     */
    public function delete(User $actor, Role $role): bool
    {
        return ! $role->is_root
            && ! $role->isDeleted()
            && $actor->holdsPermission(Permission::DeleteRoles)
            && $this->guard->canManageRole($actor, $role);
    }

    public function restore(User $actor, Role $role): bool
    {
        return $role->isDeleted()
            && $actor->holdsPermission(Permission::DeleteRoles)
            && $actor->canViewDeletedRecords()
            && $this->guard->canManageRole($actor, $role);
    }
}
