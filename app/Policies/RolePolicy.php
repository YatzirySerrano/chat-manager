<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PrivilegeGuard;
use Illuminate\Auth\Access\Response;

/**
 * The root role is invisible to anyone but the root authority: operations
 * on it answer exactly like a role that does not exist.
 */
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

    public function update(User $actor, Role $role): Response
    {
        if ($role->isHiddenFrom($actor)) {
            return Response::denyAsNotFound();
        }

        return ! $role->isDeleted()
            && $actor->holdsPermission(Permission::UpdateRoles)
            && $this->guard->canManageRole($actor, $role)
            ? Response::allow()
            : Response::deny();
    }

    /**
     * Logical deletion. The root role can never be deleted.
     */
    public function delete(User $actor, Role $role): Response
    {
        if ($role->isHiddenFrom($actor)) {
            return Response::denyAsNotFound();
        }

        return ! $role->is_root
            && ! $role->isDeleted()
            && $actor->holdsPermission(Permission::DeleteRoles)
            && $this->guard->canManageRole($actor, $role)
            ? Response::allow()
            : Response::deny();
    }

    public function restore(User $actor, Role $role): Response
    {
        if ($role->isHiddenFrom($actor)) {
            return Response::denyAsNotFound();
        }

        return $role->isDeleted()
            && $actor->holdsPermission(Permission::DeleteRoles)
            && $actor->canViewDeletedRecords()
            && $this->guard->canManageRole($actor, $role)
            ? Response::allow()
            : Response::deny();
    }
}
