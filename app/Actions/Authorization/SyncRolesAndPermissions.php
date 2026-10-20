<?php

namespace App\Actions\Authorization;

use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Brings the permission catalog into the database without touching what was
 * customized from the interface. It is idempotent and safe on every deploy:
 *
 * - creates the catalog permissions that are missing;
 * - grants the newly created permissions to the root role(s) only, so the
 *   administrative authority keeps total control as modules ship;
 * - creates the initial roles only on an installation without roles;
 * - never changes the scope or state of existing roles, nor the permissions
 *   of any non-root role;
 * - only deletes permissions no longer in the catalog when asked to prune.
 */
class SyncRolesAndPermissions
{
    public function __construct(private PermissionRegistrar $registrar) {}

    /**
     * @return array{created_permissions: list<string>, created_roles: list<string>, granted_to_root: list<string>, obsolete_permissions: list<string>, pruned: bool}
     */
    public function handle(bool $prune = false): array
    {
        $this->registrar->forgetCachedPermissions();

        $result = DB::transaction(function () use ($prune): array {
            $catalog = Permission::values();
            $existing = PermissionModel::query()->where('guard_name', 'web')->pluck('name')->all();

            $missing = array_values(array_diff($catalog, $existing));

            foreach ($missing as $name) {
                PermissionModel::query()->create(['name' => $name, 'guard_name' => 'web']);
            }

            $obsolete = array_values(array_diff($existing, $catalog));

            if ($prune && $obsolete !== []) {
                PermissionModel::query()->where('guard_name', 'web')->whereIn('name', $obsolete)->delete();
            }

            // Model events may be muted (e.g. seeders using WithoutModelEvents),
            // so the permission cache is refreshed explicitly before assigning.
            $this->registrar->forgetCachedPermissions();

            $createdRoles = [];
            $grantedToRoot = [];

            if ($missing !== []) {
                foreach (Role::query()->where('is_root', true)->where('guard_name', 'web')->get() as $root) {
                    $root->givePermissionTo($missing);
                    $grantedToRoot[] = $root->label;
                }
            }

            if (Role::query()->doesntExist()) {
                foreach (DefaultRole::cases() as $default) {
                    $role = Role::query()->create([
                        'name' => $default->value,
                        'label' => $default->label(),
                        'description' => $default->description(),
                        'guard_name' => 'web',
                        'scope' => $default->scope(),
                        'is_root' => $default->isRoot(),
                    ]);
                    $role->syncPermissions(array_map(fn (Permission $permission): string => $permission->value, $default->permissions()));
                    $createdRoles[] = $default->label();
                }
            }

            return [
                'created_permissions' => $missing,
                'created_roles' => $createdRoles,
                'granted_to_root' => $grantedToRoot,
                'obsolete_permissions' => $obsolete,
                'pruned' => $prune && $obsolete !== [],
            ];
        });

        $this->registrar->forgetCachedPermissions();

        return $result;
    }
}
