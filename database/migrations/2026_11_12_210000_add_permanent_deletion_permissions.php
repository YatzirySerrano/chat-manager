<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Group «Eliminación definitiva»: erasing a chat with its messages and
     * files, and erasing records that were already deleted (tags, request
     * categories, quick replies). They are reserved to the root authority
     * (Permission::isReservedToRoot()), so they are granted to the root
     * roles only: no other role receives them here, from the roles screen
     * or from app:sync-permissions --upgrade-default-roles.
     */
    private const PERMISSIONS = [
        'permanent-deletion.conversations',
        'permanent-deletion.tags',
        'permanent-deletion.categories',
        'permanent-deletion.quick-replies',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();
            $rootRoleIds = DB::table('roles')->where('guard_name', 'web')->where('is_root', true)->pluck('id');

            foreach (self::PERMISSIONS as $permission) {
                DB::table('permissions')->insertOrIgnore(['name' => $permission, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);

                $permissionId = DB::table('permissions')->where('guard_name', 'web')->where('name', $permission)->value('id');

                foreach ($rootRoleIds as $roleId) {
                    DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
                }
            }
        });

        Cache::forget((string) config('permission.cache.key'));
    }

    /**
     * Reverse the migrations: the permissions are removed with their grants
     * (foreign keys cascade).
     */
    public function down(): void
    {
        DB::transaction(function (): void {
            DB::table('permissions')->where('guard_name', 'web')->whereIn('name', self::PERMISSIONS)->delete();
        });

        Cache::forget((string) config('permission.cache.key'));
    }
};
