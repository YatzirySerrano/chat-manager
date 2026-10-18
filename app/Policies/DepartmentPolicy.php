<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Department;
use App\Models\User;
use App\Services\PrivilegeGuard;

/**
 * Each check combines the permission (what) with the data scope (which
 * departments): global roles reach every department, others only their own.
 */
class DepartmentPolicy
{
    public function __construct(private PrivilegeGuard $guard) {}

    public function viewAny(User $actor): bool
    {
        return $actor->holdsPermission(Permission::ViewDepartments);
    }

    public function view(User $actor, Department $department): bool
    {
        return $actor->holdsPermission(Permission::ViewDepartments)
            && $this->guard->reachesDepartment($actor, $department);
    }

    public function create(User $actor): bool
    {
        return $actor->holdsPermission(Permission::CreateDepartments);
    }

    public function update(User $actor, Department $department): bool
    {
        return $actor->holdsPermission(Permission::UpdateDepartments)
            && $this->guard->reachesDepartment($actor, $department);
    }

    public function changeStatus(User $actor, Department $department): bool
    {
        return $actor->holdsPermission(Permission::ChangeDepartmentStatus);
    }

    public function manageMembers(User $actor, Department $department): bool
    {
        return $actor->holdsPermission(Permission::ManageDepartmentMembers)
            && $this->guard->reachesDepartment($actor, $department);
    }

    /**
     * Whether the actor can add or remove this specific member: never someone
     * whose role the actor could not assign (e.g. a peer or a superior).
     */
    public function manageMember(User $actor, Department $department, User $member): bool
    {
        return $this->manageMembers($actor, $department)
            && $this->guard->canManageMembership($actor, $department, $member);
    }
}
