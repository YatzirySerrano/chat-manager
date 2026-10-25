<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * New capabilities and the existing permission whose holders already
     * exercised them, so nobody loses what they could do: supervisors who
     * assign conversations keep supervising them, whoever synchronizes
     * templates keeps sending them, and whoever edits users may supervise
     * their personal items.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const TRANSFERS = [
        ['conversations.assign', 'conversations.supervise'],
        ['templates.manage', 'templates.send'],
        ['users.update', 'supervision.view-personal'],
        ['users.update', 'supervision.manage-personal'],
        ['quick-replies.manage', 'quick-replies.personal'],
    ];

    /**
     * Every permission this migration introduces. Like every new permission
     * they are also granted to the root roles.
     *
     * @var list<string>
     */
    private const NEW_PERMISSIONS = [
        'conversations.supervise',
        'templates.send',
        'quick-replies.personal',
        'supervision.view-personal',
        'supervision.manage-personal',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $this->createPermissions(self::NEW_PERMISSIONS);
            $this->transferGrants(self::TRANSFERS);
            $this->grantToRootRoles(self::NEW_PERMISSIONS);
        });

        Cache::forget((string) config('permission.cache.key'));
    }

    /**
     * Reverse the migrations: the permissions are removed with their grants.
     */
    public function down(): void
    {
        DB::transaction(function (): void {
            $this->deletePermissions(self::NEW_PERMISSIONS);
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
     * @param  list<array{0: string, 1: string}>  $transfers  [source name, target name]
     */
    private function transferGrants(array $transfers): void
    {
        $ids = DB::table('permissions')->where('guard_name', 'web')->pluck('id', 'name');

        foreach ($transfers as [$source, $target]) {
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
