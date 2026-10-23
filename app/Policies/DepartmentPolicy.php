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
     * consult deleted records, and so does one outside the actor's scope.
     */
    public function view(User $actor, Department $department): Response
    {
        return $this->outOfReach($actor, $department, Permission::ViewDepartments) ?? Response::allow();
    }

    public function create(User $actor): bool
    {
        return $actor->holdsPermission(Permission::CreateDepartments);
    }

    public function update(User $actor, Department $department): Response
    {
        return $this->outOfReach($actor, $department, Permission::UpdateDepartments)
            ?? ($department->isDeleted() ? Response::deny() : Response::allow());
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
    public function manageMembers(User $actor, Department $department): Response
    {
        return $this->outOfReach($actor, $department, Permission::ManageDepartmentMembers)
            ?? ($department->isDeleted() ? Response::deny() : Response::allow());
    }

    /**
     * Whether the actor can add or remove this specific member: never someone
     * whose role the actor could not assign (e.g. a peer or a superior).
     */
    public function manageMember(User $actor, Department $department, User $member): Response
    {
        $response = $this->manageMembers($actor, $department);

        if ($response->denied()) {
            return $response;
        }

        return $this->guard->canManageMembership($actor, $department, $member) ? Response::allow() : Response::deny();
    }

    /**
     * Without the permission the answer is 403. With it, a department
     * outside the actor's scope (or deleted, for someone who cannot consult
     * deleted records) answers exactly like an id that does not exist.
     * Null when the department is within reach.
     */
    private function outOfReach(User $actor, Department $department, Permission $permission): ?Response
    {
        if (! $actor->holdsPermission($permission)) {
            return Response::deny();
        }

        if (($department->isDeleted() && ! $actor->canViewDeletedRecords()) || ! $this->guard->reachesDepartment($actor, $department)) {
            return Response::denyAsNotFound();
        }

        return null;
    }
}
