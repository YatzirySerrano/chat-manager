<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Department;
use App\Models\User;
use App\Services\PrivilegeGuard;
use Illuminate\Auth\Access\Response;

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

    /**
     * A deleted department answers as nonexistent to people who cannot
     * consult deleted records.
     */
    public function view(User $actor, Department $department): Response
    {
        if (! $actor->holdsPermission(Permission::ViewDepartments)) {
            return Response::deny();
        }

        if ($department->isDeleted() && ! $actor->canViewDeletedRecords()) {
            return Response::denyAsNotFound();
        }

        return $this->guard->reachesDepartment($actor, $department) ? Response::allow() : Response::deny();
    }

    public function create(User $actor): bool
    {
        return $actor->holdsPermission(Permission::CreateDepartments);
    }

    public function update(User $actor, Department $department): bool
    {
        return ! $department->isDeleted()
            && $actor->holdsPermission(Permission::UpdateDepartments)
            && $this->guard->reachesDepartment($actor, $department);
    }

    /**
     * Logical deletion (organization-wide permission).
     */
    public function delete(User $actor, Department $department): bool
    {
        return ! $department->isDeleted()
            && $actor->holdsPermission(Permission::DeleteDepartments);
    }

    public function restore(User $actor, Department $department): bool
    {
        return $department->isDeleted()
            && $actor->holdsPermission(Permission::DeleteDepartments)
            && $actor->canViewDeletedRecords();
    }

    /**
     * Memberships of a deleted department are kept but inert.
     */
    public function manageMembers(User $actor, Department $department): bool
    {
        return ! $department->isDeleted()
            && $actor->holdsPermission(Permission::ManageDepartmentMembers)
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
