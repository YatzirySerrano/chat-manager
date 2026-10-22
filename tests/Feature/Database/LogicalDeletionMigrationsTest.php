<?php

use App\Enums\DefaultRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The data migrations only touch rows, so their logic runs inside the test
 * transaction without rebuilding the schema.
 */
function dataMigration(string $name): Migration
{
    return require database_path("migrations/{$name}.php");
}

function permissionId(string $name): ?int
{
    $id = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');

    return $id === null ? null : (int) $id;
}

/**
 * @return list<string>
 */
function grantedTo(Role $role): array
{
    return DB::table('role_has_permissions')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->where('role_id', $role->id)
        ->orderBy('permissions.name')
        ->pluck('permissions.name')
        ->all();
}

test('the status permissions are replaced by the delete permissions keeping every grant, and it can be reversed', function () {
    $now = now();
    $old = ['users.change-status', 'roles.change-status', 'departments.change-status', 'contacts.archive'];
    DB::table('permissions')->whereIn('name', ['departments.delete', 'contacts.delete', 'records.view-deleted', 'contacts.import'])->delete();

    foreach ($old as $name) {
        DB::table('permissions')->insert(['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
    }

    $custom = Role::factory()->create();
    DB::table('role_has_permissions')->insert([
        ['role_id' => $custom->id, 'permission_id' => permissionId('users.change-status')],
        ['role_id' => $custom->id, 'permission_id' => permissionId('contacts.archive')],
    ]);
    $user = User::factory()->create();
    DB::table('model_has_permissions')->insert(['permission_id' => permissionId('departments.change-status'), 'model_type' => 'user', 'model_id' => $user->id]);
    $root = role(DefaultRole::SuperAdministrator);
    DB::table('role_has_permissions')->insert(['role_id' => $root->id, 'permission_id' => permissionId('roles.change-status')]);

    $migration = dataMigration('2026_10_21_224321_replace_status_permissions_with_logical_deletion');
    $migration->up();

    expect(DB::table('permissions')->whereIn('name', $old)->exists())->toBeFalse()
        ->and(grantedTo($custom))->toBe(['contacts.delete', 'users.delete'])
        ->and(DB::table('model_has_permissions')->where('model_id', $user->id)->pluck('permission_id')->all())->toBe([permissionId('departments.delete')])
        ->and(grantedTo($root))->toContain('roles.delete', 'records.view-deleted', 'contacts.import')
        ->and(grantedTo(role(DefaultRole::Agent)))->not->toContain('records.view-deleted');

    $migration->up();

    expect(grantedTo($custom))->toBe(['contacts.delete', 'users.delete']);

    $migration->down();

    expect(grantedTo($custom))->toBe(['contacts.archive', 'users.change-status', 'users.delete'])
        ->and(DB::table('model_has_permissions')->where('model_id', $user->id)->pluck('permission_id')->all())->toBe([permissionId('departments.change-status')])
        ->and(permissionId('records.view-deleted'))->toBeNull()
        ->and(permissionId('departments.delete'))->toBeNull();
});

test('the root role is presented as Superadministrador only when it still has the default label', function () {
    $root = role(DefaultRole::SuperAdministrator);
    $administrator = role(DefaultRole::Administrator);
    $administrator->forceFill(['label' => 'Administración operativa'])->save();
    $root->forceFill(['label' => 'Administrador'])->save();

    $migration = dataMigration('2026_10_21_224322_rename_root_role_to_superadministrator');
    $migration->up();
    $migration->up();

    expect($root->refresh()->label)->toBe('Superadministrador');

    $root->forceFill(['label' => 'Dirección'])->save();
    $migration->up();

    expect($root->refresh()->label)->toBe('Dirección');
});

test('existing audit entries about the root authority are classified as restricted', function () {
    $root = User::factory()->superAdministrator()->create();
    $agent = User::factory()->agent()->create();
    $rootRole = role(DefaultRole::SuperAdministrator);
    $entry = fn (array $attributes): int => DB::table('audit_logs')->insertGetId([
        'action' => 'user.updated', 'actor_name' => 'X', 'restricted' => false, 'created_at' => now(), ...$attributes,
    ]);

    $byRoot = $entry(['user_id' => $root->id]);
    $aboutRoot = $entry(['user_id' => $agent->id, 'auditable_type' => 'user', 'auditable_id' => $root->id]);
    $aboutRootRole = $entry(['auditable_type' => 'role', 'auditable_id' => $rootRole->id]);
    $ordinary = $entry(['user_id' => $agent->id, 'auditable_type' => 'user', 'auditable_id' => $agent->id]);

    dataMigration('2026_10_21_224320_add_restricted_to_audit_logs_table')->up();

    expect(DB::table('audit_logs')->whereIn('id', [$byRoot, $aboutRoot, $aboutRootRole, $ordinary])->pluck('restricted', 'id')->map(fn ($value): bool => (bool) $value)->all())
        ->toBe([$byRoot => true, $aboutRoot => true, $aboutRootRole => true, $ordinary => false]);
});
