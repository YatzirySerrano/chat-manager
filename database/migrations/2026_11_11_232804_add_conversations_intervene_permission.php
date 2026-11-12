<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * conversations.intervene: write to the customer of an open
     * conversation another person attends, within the reach of the role,
     * without taking it. Like every new permission it is granted to the root
     * roles and, being not reserved, to the default Administrador role, as
     * on a new installation (DefaultRole). Supervisor, Agente and custom
     * roles gain nothing: it is granted to them explicitly from the roles
     * screen.
     */
    private const PERMISSION = 'conversations.intervene';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();

            DB::table('permissions')->insertOrIgnore(['name' => self::PERMISSION, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);

            $permissionId = DB::table('permissions')->where('guard_name', 'web')->where('name', self::PERMISSION)->value('id');
            $roleIds = DB::table('roles')
                ->where('guard_name', 'web')
                ->where(fn ($roles) => $roles->where('is_root', true)->orWhere('name', 'administrator'))
                ->pluck('id');

            foreach ($roleIds as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        });

        Cache::forget((string) config('permission.cache.key'));
    }

    /**
     * Reverse the migrations: the permission is removed with its grants
     * (foreign keys cascade).
     */
    public function down(): void
    {
        DB::transaction(function (): void {
            DB::table('permissions')->where('guard_name', 'web')->where('name', self::PERMISSION)->delete();
        });

        Cache::forget((string) config('permission.cache.key'));
    }
};
