<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Activating and deactivating users, roles and departments, and archiving
     * contacts, became their logical deletion and restoration, governed by each
     * module's delete permission. Every grant of the old permission is kept by
     * granting its replacement.
     *
     * @var array<string, string>
     */
    private const REPLACEMENTS = [
        'users.change-status' => 'users.delete',
        'roles.change-status' => 'roles.delete',
        'departments.change-status' => 'departments.delete',
        'contacts.archive' => 'contacts.delete',
    ];

    /**
     * New capabilities without a predecessor. Like every new permission they
     * are granted to the root roles only.
     *
     * @var list<string>
     */
    private const NEW_FOR_ROOT = ['records.view-deleted', 'contacts.import'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $this->createPermissions([...array_values(self::REPLACEMENTS), ...self::NEW_FOR_ROOT]);
            $this->transferGrants(self::REPLACEMENTS);
            $this->grantToRootRoles(self::NEW_FOR_ROOT);
            $this->deletePermissions(array_keys(self::REPLACEMENTS));
        });

        Cache::forget((string) config('permission.cache.key'));
    }

    /**
     * Reverse the migrations: the old permissions come back with the grants of
     * their replacements. users.delete and roles.delete existed before and
     * are kept; the permissions this migration introduced are removed.
     */
    public function down(): void
    {
        DB::transaction(function (): void {
            $this->createPermissions(array_keys(self::REPLACEMENTS));
            $this->transferGrants(array_flip(self::REPLACEMENTS));
            $this->deletePermissions(['departments.delete', 'contacts.delete', ...self::NEW_FOR_ROOT]);
        });

        Cache::forget((string) config('permission.cache.key'));
    }

    /**
     * @param  list<string>  $names
     */
    private function createPermissions(array $names): void
    {
        $now = now();

        foreach ($names as $name) {
            DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    /**
     * Grant the target permission to every role and model holding the source.
     *
     * @param  array<string, string>  $map  source name => target name
     */
    private function transferGrants(array $map): void
    {
        $ids = DB::table('permissions')->where('guard_name', 'web')->pluck('id', 'name');

        foreach ($map as $source => $target) {
            if (! isset($ids[$source], $ids[$target])) {
                continue;
            }

            foreach (DB::table('role_has_permissions')->where('permission_id', $ids[$source])->pluck('role_id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $ids[$target], 'role_id' => $roleId]);
            }

            foreach (DB::table('model_has_permissions')->where('permission_id', $ids[$source])->get(['model_type', 'model_id']) as $grant) {
                DB::table('model_has_permissions')->insertOrIgnore([
                    'permission_id' => $ids[$target],
                    'model_type' => $grant->model_type,
                    'model_id' => $grant->model_id,
                ]);
            }
        }
    }

    /**
     * @param  list<string>  $names
     */
    private function grantToRootRoles(array $names): void
    {
        $permissionIds = DB::table('permissions')->where('guard_name', 'web')->whereIn('name', $names)->pluck('id');

        foreach (DB::table('roles')->where('is_root', true)->where('guard_name', 'web')->pluck('id') as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }
    }

    /**
     * Grants are removed by the foreign keys' cascade.
     *
     * @param  list<string>  $names
     */
    private function deletePermissions(array $names): void
    {
        DB::table('permissions')->where('guard_name', 'web')->whereIn('name', $names)->delete();
    }
};
