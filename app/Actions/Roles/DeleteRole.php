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
 * Logically deletes a role: it grants nothing and is no longer offered, but
 * keeps its permissions and stays referenced by the history, so it can be
 * restored as it was.
 */
class DeleteRole
{
    public function __construct(
        private AuditLogger $audit,
        private PermissionRegistrar $registrar,
    ) {}

    /**
     * The root role can never be deleted, and neither can a role still held
     * by anyone, deleted accounts included (restoring one of them must not
     * leave it with a deleted role): those users must be moved first.
     *
     * @throws ValidationException
     */
    public function handle(Role $role, User $actor): Role
    {
        if ($role->is_root) {
            throw ValidationException::withMessages(['role' => 'El rol de superadministrador no puede eliminarse.']);
        }

        $deleted = DB::transaction(function () use ($role, $actor): Role {
            /** @var Role $locked */
            $locked = Role::query()->lockForUpdate()->findOrFail($role->id);

            if ($locked->isDeleted()) {
                return $locked;
            }

            $holders = $locked->users()->lockForUpdate()->get(['users.id', 'users.is_active']);

            if ($holders->isNotEmpty()) {
                $active = $holders->where('is_active', true)->count();
                $holdersText = implode(' y a ', array_filter([
                    $active > 0 ? $this->people($active, 'activo', 'activos') : null,
                    $holders->count() > $active ? $this->people($holders->count() - $active, 'eliminado', 'eliminados') : null,
                ]));

                throw ValidationException::withMessages([
                    'role' => "El rol {$locked->label} está asignado a {$holdersText}. Asígnales otro rol antes de eliminarlo.",
                ]);
            }

            $locked->forceFill(['is_active' => false])->save();

            $this->audit->record(AuditAction::RoleDeleted, $locked, [
                'permissions' => $locked->permissionNames(),
            ], $actor);

            return $locked;
        });

        $this->registrar->forgetCachedPermissions();

        return $deleted;
    }

    private function people(int $count, string $singular, string $plural): string
    {
        return $count === 1 ? "1 usuario {$singular}" : "{$count} usuarios {$plural}";
    }
}
