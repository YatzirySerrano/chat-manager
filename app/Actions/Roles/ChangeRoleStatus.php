<?php

namespace App\Actions\Roles;

use App\Enums\AuditAction;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * An inactive role grants nothing: its users keep their identity and history
 * but lose every permission on their next request.
 */
class ChangeRoleStatus
{
    public function __construct(
        private AuditLogger $audit,
        private PermissionRegistrar $registrar,
    ) {}

    public function handle(Role $role, bool $active, User $actor): Role
    {
        if ($role->is_root && ! $active) {
            throw ValidationException::withMessages(['role' => 'El rol con autoridad administrativa no puede desactivarse.']);
        }

        $updated = DB::transaction(function () use ($role, $active, $actor): Role {
            /** @var Role $locked */
            $locked = Role::query()->lockForUpdate()->findOrFail($role->id);

            if ($locked->is_active === $active) {
                return $locked;
            }

            $locked->forceFill(['is_active' => $active])->save();

            $this->audit->record($active ? AuditAction::RoleActivated : AuditAction::RoleDeactivated, $locked, [
                'users_affected' => $locked->users()->count(),
            ], $actor);

            return $locked;
        });

        $this->registrar->forgetCachedPermissions();

        return $updated;
    }
}
