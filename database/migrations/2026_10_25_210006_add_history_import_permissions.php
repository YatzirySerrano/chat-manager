<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Capabilities of the Whaticket history importer (Fase 5.2). Like every
     * new permission they are granted to the root roles. Consulting the
     * archived history (archive-view, not reserved) is also granted to the
     * default Administrador role, as on a new installation (DefaultRole);
     * nobody else gains access until an administrator decides it from the
     * role editor.
     *
     * @var list<string>
     */
    private const NEW_PERMISSIONS = [
        'history-import.view',
        'history-import.classify',
        'history-import.execute',
        'history-import.archive-view',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();

            foreach (self::NEW_PERMISSIONS as $name) {
                DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
            }

            $permissionIds = DB::table('permissions')->where('guard_name', 'web')->whereIn('name', self::NEW_PERMISSIONS)->pluck('id');

            foreach (DB::table('roles')->where('is_root', true)->where('guard_name', 'web')->pluck('id') as $roleId) {
                foreach ($permissionIds as $permissionId) {
                    DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
                }
            }

            $archive = DB::table('permissions')->where('guard_name', 'web')->where('name', 'history-import.archive-view')->value('id');

            foreach (DB::table('roles')->where('name', 'administrator')->where('is_root', false)->where('guard_name', 'web')->pluck('id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $archive, 'role_id' => $roleId]);
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
            DB::table('permissions')->where('guard_name', 'web')->whereIn('name', self::NEW_PERMISSIONS)->delete();
        });

        Cache::forget((string) config('permission.cache.key'));
    }
};
