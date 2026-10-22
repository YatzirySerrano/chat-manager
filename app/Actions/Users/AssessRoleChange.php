<?php

namespace App\Actions\Users;

use App\Enums\Permission;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Services\PrivilegeGuard;

class AssessRoleChange
{
    public function __construct(private PrivilegeGuard $guard) {}

    public function handle(User $user, Role $to, User $actor): RoleChangeImpact
    {
        $user->loadMissing(['roles.permissions', 'departments']);
        $to->loadMissing('permissions');

        $from = $user->role();
        $fromPermissions = $from?->permissionNames() ?? [];
        $toPermissions = $to->permissionNames();

        /** @var list<Department> $currentDepartments */
        $currentDepartments = $user->departments->sortBy('name')->values()->all();
        $departmentsToLeave = $to->scope->allowsDepartmentMembership() ? [] : $currentDepartments;

        return new RoleChangeImpact(
            from: $from,
            to: $to,
            lostPermissions: array_values(array_diff($fromPermissions, $toPermissions)),
            gainedPermissions: array_values(array_diff($toPermissions, $fromPermissions)),
            currentDepartments: $currentDepartments,
            departmentsToLeave: $departmentsToLeave,
            conversationsToRelease: $this->conversationsToRelease($user, $to, $departmentsToLeave),
            blockers: $this->blockers($user, $from, $to, $actor),
        );
    }

    /**
     * Open conversations the user would stop attending: all of them when the
     * new role cannot reply, otherwise those of the departments being left.
     *
     * @param  list<Department>  $departmentsToLeave
     */
    private function conversationsToRelease(User $user, Role $to, array $departmentsToLeave): int
    {
        $query = Conversation::query()->where('assigned_user_id', $user->id)->notClosed();

        if ($to->grants(Permission::ReplyConversations)) {
            if ($departmentsToLeave === []) {
                return 0;
            }

            $query->whereIn('department_id', array_map(fn (Department $department): int => $department->id, $departmentsToLeave));
        }

        return $query->count();
    }

    /**
     * Rules the change would break and that must be resolved first.
     *
     * @return list<string>
     */
    private function blockers(User $user, ?Role $from, Role $to, User $actor): array
    {
        $blockers = [];

        if ($user->is($actor)) {
            $blockers[] = 'No puedes modificar tu propio rol.';
        }

        if (! $to->is_active) {
            $blockers[] = "El rol {$to->label} está eliminado. Restáuralo antes de asignarlo.";
        }

        if (! $this->guard->canAssignRole($actor, $to)) {
            $blockers[] = "No puedes asignar el rol {$to->label} porque otorga más privilegios de los que administras.";
        }

        if (($from->is_root ?? false) && ! $to->is_root && $user->hasRootAuthority()
            && User::query()->withRootAuthority()->whereKeyNot($user->id)->doesntExist()) {
            $blockers[] = 'Es el último superadministrador activo. Asigna el rol de superadministrador a otra persona antes de cambiarle el rol.';
        }

        return $blockers;
    }
}
