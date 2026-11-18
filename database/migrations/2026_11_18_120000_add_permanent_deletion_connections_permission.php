<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * «Eliminar conexiones definitivamente» (group «Eliminación definitiva»):
     * erasing a connection with every conversation, message and file it
     * holds. Reserved to the root authority (Permission::isReservedToRoot()),
     * so it is granted to the root roles only.
     */
    private const PERMISSION = 'permanent-deletion.connections';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();

            DB::table('permissions')->insertOrIgnore(['name' => self::PERMISSION, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);

            $permissionId = DB::table('permissions')->where('guard_name', 'web')->where('name', self::PERMISSION)->value('id');

            foreach (DB::table('roles')->where('guard_name', 'web')->where('is_root', true)->pluck('id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        });

        Cache::forget((string) config('permission.cache.key'));
    }

    /**
     * Reverse the migrations: the permission goes with its grants (foreign
     * keys cascade).
     */
    public function down(): void
    {
        DB::table('permissions')->where('guard_name', 'web')->where('name', self::PERMISSION)->delete();

        Cache::forget((string) config('permission.cache.key'));
    }
};
