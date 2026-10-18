<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Services\PrivilegeGuard;

class UserPolicy
{
    public function __construct(private PrivilegeGuard $guard) {}

    public function viewAny(User $actor): bool
    {
        return $actor->holdsPermission(Permission::ViewUsers);
    }

    /**
     * Every account needs a role, so creating one also requires assigning roles.
     */
    public function create(User $actor): bool
    {
        return $actor->holdsPermission(Permission::CreateUsers)
            && $actor->holdsPermission(Permission::AssignRoles);
    }

    /**
     * Profile data can be edited on one's own account or on accounts the actor manages.
     */
    public function update(User $actor, User $user): bool
    {
        return $actor->holdsPermission(Permission::UpdateUsers)
            && ($actor->is($user) || $this->guard->canManageUser($actor, $user));
    }

    public function assignRole(User $actor, User $user): bool
    {
        return $actor->holdsPermission(Permission::AssignRoles)
            && $this->guard->canManageUser($actor, $user);
    }

    public function changeStatus(User $actor, User $user): bool
    {
        return $actor->holdsPermission(Permission::ChangeUserStatus)
            && $this->guard->canManageUser($actor, $user);
    }

    public function delete(User $actor, User $user): bool
    {
        return $actor->holdsPermission(Permission::DeleteUsers)
            && $this->guard->canManageUser($actor, $user);
    }
}
