<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * connections.ai («Configurar la inteligencia artificial»): the provider
     * and API key, the business information and which connections the
     * assistant answers. It is granted to the roles that already manage
     * connections (connections.manage), which includes the root roles and
     * the default Administrador; Supervisor, Agente and custom roles without
     * connections.manage gain nothing.
     */
    private const PERMISSION = 'connections.ai';

    private const MANAGES_CONNECTIONS = 'connections.manage';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();

            DB::table('permissions')->insertOrIgnore(['name' => self::PERMISSION, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);

            $permissionId = DB::table('permissions')->where('guard_name', 'web')->where('name', self::PERMISSION)->value('id');
            $managesId = DB::table('permissions')->where('guard_name', 'web')->where('name', self::MANAGES_CONNECTIONS)->value('id');

            $roleIds = DB::table('roles')
                ->where('guard_name', 'web')
                ->where(fn ($roles) => $roles
                    ->where('is_root', true)
                    ->when($managesId !== null, fn ($query) => $query->orWhereIn('id', DB::table('role_has_permissions')->where('permission_id', $managesId)->select('role_id'))))
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
