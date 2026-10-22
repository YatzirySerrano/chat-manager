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
     * Profile data can be edited on one's own account or on accounts the
     * actor manages. A deleted account is restored first.
     */
    public function update(User $actor, User $user): bool
    {
        return ! $user->isDeleted()
            && $actor->holdsPermission(Permission::UpdateUsers)
            && ($actor->is($user) || $this->guard->canManageUser($actor, $user));
    }

    public function assignRole(User $actor, User $user): bool
    {
        return ! $user->isDeleted()
            && $actor->holdsPermission(Permission::AssignRoles)
            && $this->guard->canManageUser($actor, $user);
    }

    /**
     * Logical deletion. Never one's own account, and only accounts the actor
     * manages: a non-root actor never reaches a Superadministrador.
     */
    public function delete(User $actor, User $user): bool
    {
        return ! $user->isDeleted()
            && $actor->holdsPermission(Permission::DeleteUsers)
            && $this->guard->canManageUser($actor, $user);
    }

    /**
     * Restoring requires seeing deleted records besides the delete permission.
     */
    public function restore(User $actor, User $user): bool
    {
        return $user->isDeleted()
            && $actor->holdsPermission(Permission::DeleteUsers)
            && $actor->canViewDeletedRecords()
            && $this->guard->canManageUser($actor, $user);
    }
}
