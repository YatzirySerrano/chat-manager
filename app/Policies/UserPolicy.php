<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Services\PrivilegeGuard;
use Illuminate\Auth\Access\Response;

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
    public function update(User $actor, User $user): Response
    {
        if ($denied = $this->outOfReach($actor, $user, Permission::UpdateUsers)) {
            return $denied;
        }

        return ! $user->isDeleted() && ($actor->is($user) || $this->guard->canManageUser($actor, $user))
            ? Response::allow()
            : Response::deny();
    }

    public function assignRole(User $actor, User $user): Response
    {
        if ($denied = $this->outOfReach($actor, $user, Permission::AssignRoles)) {
            return $denied;
        }

        return ! $user->isDeleted() && $this->guard->canManageUser($actor, $user) ? Response::allow() : Response::deny();
    }

    /**
     * Logical deletion. Never one's own account, and only accounts the actor
     * manages: a non-root actor never reaches a Superadministrador.
     */
    public function delete(User $actor, User $user): Response
    {
        if ($denied = $this->outOfReach($actor, $user, Permission::DeleteUsers)) {
            return $denied;
        }

        return ! $user->isDeleted() && $this->guard->canManageUser($actor, $user) ? Response::allow() : Response::deny();
    }

    /**
     * Restoring requires seeing deleted records besides the delete permission.
     */
    public function restore(User $actor, User $user): Response
    {
        if ($denied = $this->outOfReach($actor, $user, Permission::DeleteUsers)) {
            return $denied;
        }

        return $user->isDeleted() && $actor->canViewDeletedRecords() && $this->guard->canManageUser($actor, $user)
            ? Response::allow()
            : Response::deny();
    }

    /**
     * Without the permission the answer is 403. With it, an account outside
     * the actor's data scope (or deleted, for someone who cannot consult
     * deleted records) answers exactly like an id that does not exist, so
     * identifiers reveal nothing. Null when the account is within reach.
     */
    private function outOfReach(User $actor, User $user, Permission $permission): ?Response
    {
        if (! $actor->holdsPermission($permission)) {
            return Response::deny();
        }

        if ($actor->is($user)) {
            return null;
        }

        if (! $this->guard->withinScope($actor, $user) || ($user->isDeleted() && ! $actor->canViewDeletedRecords())) {
            return Response::denyAsNotFound();
        }

        return null;
    }
}
