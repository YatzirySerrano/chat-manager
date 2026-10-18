<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @return list<string>
 */
function rolePermissionNames(string $role): array
{
    return DB::table('role_has_permissions')
        ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->where('roles.name', $role)
        ->orderBy('permissions.name')
        ->pluck('permissions.name')
        ->all();
}

test('the migration to manageable roles keeps users, assignments and capabilities, and can be reversed', function () {
    $supervisor = User::factory()->supervisor()->create();
    $migration = '2026_10_16_233130_add_management_columns_to_roles_table';

    $this->artisan('migrate:rollback', ['--path' => "database/migrations/{$migration}.php", '--force' => true])->assertSuccessful();

    expect(Schema::hasColumn('roles', 'scope'))->toBeFalse()
        ->and(rolePermissionNames('supervisor'))->toContain('departments.manage-agents', 'conversations.view-assigned')
        ->and(rolePermissionNames('admin'))->toContain('users.manage', 'settings.manage');

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    $roles = DB::table('roles')->orderBy('name')->get(['name', 'label', 'scope', 'is_root', 'is_active'])->keyBy('name');

    expect($roles['admin'])->label->toBe('Administrador')->scope->toBe('global')->is_root->toBe(1)
        ->and($roles['supervisor'])->scope->toBe('departments')->is_root->toBe(0)
        ->and($roles['agent'])->scope->toBe('assigned')->is_active->toBe(1)
        ->and(rolePermissionNames('supervisor'))->toContain('departments.manage-members', 'conversations.view')
        ->and(rolePermissionNames('supervisor'))->not->toContain('departments.manage-agents')
        ->and(rolePermissionNames('admin'))->toContain('users.assign-roles', 'roles.update', 'settings.update', 'appearance.update')
        ->and(DB::table('model_has_roles')->where('model_id', $supervisor->id)->count())->toBe(1);
});
