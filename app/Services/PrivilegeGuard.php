<?php

namespace App\Services;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;

/**
 * Rules that prevent privilege escalation. Nobody can grant, assign or manage
 * more than they hold; only the root authority (Superadministrador) reaches
 * every role and user, and permissions reserved to it never reach another
 * role.
 */
class PrivilegeGuard
{
    /**
     * Whether the actor can give the role to someone. Non-root actors can only
     * assign roles below their own: never their own role, the root role, a
     * wider scope or permissions they do not hold.
     */
    public function canAssignRole(User $actor, Role $role): bool
    {
        if ($actor->hasRootAuthority()) {
            return true;
        }

        $actorRole = $actor->activeRole();

        if ($actorRole === null || $role->is_root || $actorRole->is($role)) {
            return false;
        }

        return $actorRole->scope->covers($role->scope)
            && $this->canGrantPermissions($actor, $role->permissionNames());
    }

    /**
     * Whether the actor can edit the definition of the role.
     */
    public function canManageRole(User $actor, Role $role): bool
    {
        return $this->canAssignRole($actor, $role);
    }

    /**
     * @param  list<string>  $permissionNames
     */
    public function canGrantPermissions(User $actor, array $permissionNames): bool
    {
        if ($actor->hasRootAuthority()) {
            return true;
        }

        return array_diff($permissionNames, $actor->effectivePermissions()) === [];
    }

    /**
     * Permissions reserved to the root authority among the given ones that
     * the role may not hold: every reserved one unless the role is the root
     * role. Applies to every actor, the root authority included.
     *
     * @param  list<string>  $permissionNames
     * @return list<string>
     */
    public function reservedPermissionsOutsideRoot(?Role $role, array $permissionNames): array
    {
        if ($role->is_root ?? false) {
            return [];
        }

        return array_values(array_intersect($permissionNames, Permission::reservedToRootValues()));
    }

    public function canGrantScope(User $actor, DataScope $scope): bool
    {
        return $actor->hasRootAuthority() || ($actor->dataScope()?->covers($scope) ?? false);
    }

    /**
     * Whether the actor can act on another user's account: the user must be
     * within the actor's data scope and hold a role the actor could assign.
     */
    public function canManageUser(User $actor, User $user): bool
    {
        if ($actor->is($user) || ! $this->withinScope($actor, $user)) {
            return false;
        }

        $role = $user->role();

        return $role === null || $this->canAssignRole($actor, $role);
    }

    /**
     * Whether the actor can add or remove the member from the department.
     */
    public function canManageMembership(User $actor, Department $department, User $member): bool
    {
        if (! $actor->hasRootAuthority() && $actor->is($member)) {
            return false;
        }

        $role = $member->role();

        return $role === null || $this->canAssignRole($actor, $role);
    }

    public function withinScope(User $actor, User $user): bool
    {
        return match ($actor->dataScope()) {
            DataScope::Global => true,
            DataScope::Departments => array_intersect($actor->departmentIds(), $user->departmentIds()) !== [],
            default => false,
        };
    }

    /**
     * Whether the actor can operate on the department according to scope.
     */
    public function reachesDepartment(User $actor, Department|int $department): bool
    {
        return match ($actor->dataScope()) {
            DataScope::Global => true,
            DataScope::Departments, DataScope::Assigned => $actor->belongsToDepartment($department),
            default => false,
        };
    }
}
