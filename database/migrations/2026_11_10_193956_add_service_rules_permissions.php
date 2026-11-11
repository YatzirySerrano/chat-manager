<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Capabilities of the service rules: managing the catalog of request
     * categories, confirming that a request is complete (or returning it to
     * «necesita información») and making justified exceptions to the queue
     * rules. Like every new permission they are granted to the root roles
     * and, being not reserved, to the default Administrador role, as on a
     * new installation (DefaultRole). Existing Supervisor roles gain nothing
     * here: an installation brings them up to their definition on request
     * (app:sync-permissions --upgrade-default-roles).
     *
     * @var list<string>
     */
    private const NEW_PERMISSIONS = [
        'categories.manage',
        'conversations.confirm-ready',
        'conversations.override-queue-rules',
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
