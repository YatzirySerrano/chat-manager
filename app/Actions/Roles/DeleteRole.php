<?php

namespace App\Actions\Roles;

use App\Enums\AuditAction;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

class DeleteRole
{
    public function __construct(
        private AuditLogger $audit,
        private PermissionRegistrar $registrar,
    ) {}

    /**
     * Roles still assigned to someone cannot be deleted: those users must be
     * moved to another role first.
     */
    public function handle(Role $role, User $actor): void
    {
        if ($role->is_root) {
            throw ValidationException::withMessages(['role' => 'El rol con autoridad administrativa no puede eliminarse.']);
        }

        DB::transaction(function () use ($role, $actor): void {
            /** @var Role $locked */
            $locked = Role::query()->lockForUpdate()->findOrFail($role->id);
            $usersCount = $locked->users()->lockForUpdate()->count();

            if ($usersCount > 0) {
                throw ValidationException::withMessages([
                    'role' => "El rol {$locked->label} está asignado a {$usersCount} ".($usersCount === 1 ? 'usuario' : 'usuarios').'. Asígnales otro rol antes de eliminarlo.',
                ]);
            }

            $this->audit->record(AuditAction::RoleDeleted, $locked, [
                'label' => $locked->label,
                'permissions' => $locked->permissionNames(),
            ], $actor);

            $locked->delete();
        });

        $this->registrar->forgetCachedPermissions();
    }
}
