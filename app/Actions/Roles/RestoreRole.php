<?php

namespace App\Actions\Roles;

use App\Enums\AuditAction;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Restores a logically deleted role with the permissions it had.
 */
class RestoreRole
{
    public function __construct(
        private AuditLogger $audit,
        private PermissionRegistrar $registrar,
    ) {}

    public function handle(Role $role, User $actor): Role
    {
        $restored = DB::transaction(function () use ($role, $actor): Role {
            /** @var Role $locked */
            $locked = Role::query()->lockForUpdate()->findOrFail($role->id);

            if (! $locked->isDeleted()) {
                return $locked;
            }

            $locked->forceFill(['is_active' => true])->save();

            $this->audit->record(AuditAction::RoleRestored, $locked, [], $actor);

            return $locked;
        });

        $this->registrar->forgetCachedPermissions();

        return $restored;
    }
}
