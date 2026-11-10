<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Capabilities of «Seguimiento de atención» and of the control of new
     * conversations. Like every new permission they are granted to the root
     * roles and, being not reserved, to the default Administrador role, as
     * on a new installation (DefaultRole). Supervisors and agents gain
     * nothing until an administrator decides it from the role editor, so
     * every agent stays subject to the control of pending conversations.
     *
     * @var list<string>
     */
    private const NEW_PERMISSIONS = [
        'attention.view',
        'attention.alert',
        'conversations.accept-with-pending',
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
            $roleIds = DB::table('roles')
                ->where('guard_name', 'web')
                ->where(fn ($roles) => $roles->where('is_root', true)->orWhere('name', 'administrator'))
                ->pluck('id');

            foreach ($roleIds as $roleId) {
                foreach ($permissionIds as $permissionId) {
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
            DB::table('permissions')->where('guard_name', 'web')->whereIn('name', self::NEW_PERMISSIONS)->delete();
        });

        Cache::forget((string) config('permission.cache.key'));
    }
};
