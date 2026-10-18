<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permission names before roles became manageable from the interface,
     * mapped to the capabilities that replace them.
     *
     * @var array<string, list<string>>
     */
    private const PERMISSION_MAP = [
        'dashboard.view' => ['dashboard.view'],
        'users.manage' => [
            'users.view', 'users.create', 'users.update', 'users.change-status', 'users.assign-roles', 'users.delete',
            'roles.view', 'roles.create', 'roles.update', 'roles.change-status', 'roles.delete',
        ],
        'departments.view' => ['departments.view'],
        'departments.manage' => ['departments.view', 'departments.create', 'departments.update', 'departments.change-status', 'departments.manage-members'],
        'departments.manage-agents' => ['departments.manage-members'],
        'settings.manage' => ['settings.view', 'settings.update', 'appearance.view', 'appearance.update'],
        'audit.view' => ['audit.view'],
        'conversations.view-all' => ['conversations.view', 'conversations.history'],
        'conversations.view-department' => ['conversations.view', 'conversations.history'],
        'conversations.view-assigned' => ['conversations.view', 'conversations.history'],
        'conversations.assign' => ['conversations.assign'],
        'conversations.transfer' => ['conversations.transfer'],
        'conversations.reply' => ['conversations.reply'],
        'conversations.close' => ['conversations.close'],
        'quick-replies.use' => ['quick-replies.use'],
        'quick-replies.manage' => ['quick-replies.manage'],
        'reports.view' => ['reports.view'],
    ];

    /**
     * Presentation and scope of the roles that existed before this migration.
     *
     * @var array<string, array{label: string, description: string, scope: string, is_root: bool}>
     */
    private const KNOWN_ROLES = [
        'admin' => ['label' => 'Administrador', 'description' => 'Autoridad administrativa de la plataforma: usuarios, roles, departamentos y configuración.', 'scope' => 'global', 'is_root' => true],
        'supervisor' => ['label' => 'Supervisor', 'description' => 'Supervisa las conversaciones y los miembros de sus departamentos.', 'scope' => 'departments', 'is_root' => false],
        'agent' => ['label' => 'Agente', 'description' => 'Atiende las conversaciones que tiene asignadas.', 'scope' => 'assigned', 'is_root' => false],
    ];

    /**
     * Run the migrations.
     *
     * Roles become records managed from the interface: they gain a visible
     * name, a description, a data scope, an active flag and the protected
     * root authority flag. Existing permissions are translated to the new
     * catalog keeping every user's role and the capabilities it had.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('label', 80)->nullable()->after('name');
            $table->string('description', 255)->nullable()->after('label');
            $table->string('scope', 20)->default('assigned')->after('guard_name');
            $table->boolean('is_active')->default(true)->after('scope');
            $table->boolean('is_root')->default(false)->after('is_active');
        });

        DB::transaction(function (): void {
            foreach (DB::table('roles')->get(['id', 'name']) as $role) {
                $known = self::KNOWN_ROLES[$role->name] ?? null;

                DB::table('roles')->where('id', $role->id)->update([
                    'label' => $known['label'] ?? str($role->name)->headline()->limit(80, '')->value(),
                    'description' => $known['description'] ?? null,
                    'scope' => $known['scope'] ?? 'assigned',
                    'is_root' => $known['is_root'] ?? false,
                ]);
            }

            $this->translatePermissions(self::PERMISSION_MAP);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->string('label', 80)->nullable(false)->change();
            $table->unique(['label', 'guard_name']);
            $table->index('is_root');
        });

        Cache::forget((string) config('permission.cache.key'));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $reverse = [];

        foreach (self::PERMISSION_MAP as $old => $newPermissions) {
            if (count($newPermissions) === 1 || $old === 'conversations.view-assigned') {
                foreach ($newPermissions as $new) {
                    $reverse[$new] ??= [];
                    $reverse[$new][] = $old;
                }
            }
        }

        $reverse['users.view'] = ['users.manage'];
        $reverse['departments.create'] = ['departments.manage'];
        $reverse['settings.update'] = ['settings.manage'];

        DB::transaction(fn () => $this->translatePermissions($reverse));

        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique(['label', 'guard_name']);
            $table->dropIndex(['is_root']);
            $table->dropColumn(['label', 'description', 'scope', 'is_active', 'is_root']);
        });

        Cache::forget((string) config('permission.cache.key'));
    }

    /**
     * Replace the permissions of every role according to the map, creating the
     * target permissions and deleting the ones that are no longer mapped to.
     *
     * @param  array<string, list<string>>  $map
     */
    private function translatePermissions(array $map): void
    {
        $now = now();
        $targetNames = array_values(array_unique(array_merge(...array_values($map))));

        foreach ($targetNames as $name) {
            DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        }

        $permissionIds = DB::table('permissions')->where('guard_name', 'web')->pluck('id', 'name');
        $sourceNames = array_keys($map);

        $grants = DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->whereIn('permissions.name', $sourceNames)
            ->get(['role_has_permissions.role_id', 'permissions.name']);

        foreach ($grants as $grant) {
            foreach ($map[$grant->name] as $target) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'role_id' => $grant->role_id,
                    'permission_id' => $permissionIds[$target],
                ]);
            }
        }

        $obsolete = array_diff($sourceNames, $targetNames);

        DB::table('permissions')->where('guard_name', 'web')->whereIn('name', $obsolete)->delete();
    }
};
