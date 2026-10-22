<?php

use App\Enums\DataScope;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\Role;
use Spatie\Permission\Models\Permission as PermissionModel;

test('synchronizing never overwrites roles customized from the interface', function () {
    $agent = role(DefaultRole::Agent);
    $agent->syncPermissions(['dashboard.view', 'audit.view']);
    $agent->forceFill(['label' => 'Agente Senior', 'is_active' => false])->save();
    $administrator = role(DefaultRole::Administrator);
    $administrator->syncPermissions(['dashboard.view']);

    $this->artisan('app:sync-permissions')->assertSuccessful();

    $agent->refresh()->load('permissions');

    expect($agent->label)->toBe('Agente Senior')
        ->and($agent->is_active)->toBeFalse()
        ->and($agent->permissionNames())->toEqualCanonicalizing(['dashboard.view', 'audit.view'])
        ->and($administrator->refresh()->load('permissions')->permissionNames())->toBe(['dashboard.view'])
        ->and(Role::query()->where('name', 'agent')->count())->toBe(1)
        ->and(Role::query()->where('label', 'Agente')->exists())->toBeFalse();
});

test('a default role that does not exist yet is created on an existing installation', function () {
    $administrator = role(DefaultRole::Administrator);
    $administrator->users()->detach();
    $administrator->delete();
    $root = role(DefaultRole::SuperAdministrator);

    $this->artisan('app:sync-permissions')->expectsOutputToContain('Roles iniciales creados: Administrador.')->assertSuccessful();

    $created = role(DefaultRole::Administrator)->load('permissions');

    expect($created)
        ->label->toBe('Administrador')
        ->is_root->toBeFalse()
        ->scope->toBe(DataScope::Global)
        ->and($created->permissionNames())->toContain(Permission::DeleteUsers->value, Permission::ViewDeletedRecords->value)
        ->and($created->permissionNames())->not->toContain(Permission::ImportContacts->value)
        ->and(Role::query()->where('is_root', true)->sole()->is($root))->toBeTrue();

    $this->artisan('app:sync-permissions')->expectsOutputToContain('Los roles existentes y sus permisos no se modificaron.')->assertSuccessful();

    expect(Role::query()->where('name', DefaultRole::Administrator->value)->count())->toBe(1);
});

test('a default role is not created when its label is already taken', function () {
    $administrator = role(DefaultRole::Administrator);
    $administrator->delete();
    Role::factory()->create(['label' => 'Administrador']);

    $this->artisan('app:sync-permissions')->assertSuccessful();

    expect(Role::query()->where('name', DefaultRole::Administrator->value)->exists())->toBeFalse()
        ->and(Role::query()->where('label', 'Administrador')->count())->toBe(1);
});

test('synchronizing adds missing catalog permissions and is idempotent', function () {
    PermissionModel::query()->where('name', Permission::ExportReports->value)->delete();

    $this->artisan('app:sync-permissions')
        ->expectsOutputToContain('1 permisos agregados al catálogo.')
        ->assertSuccessful();

    $this->artisan('app:sync-permissions')
        ->expectsOutputToContain('El catálogo de permisos ya estaba completo.')
        ->assertSuccessful();

    expect(PermissionModel::query()->count())->toBe(count(Permission::cases()));
});

test('permissions outside the catalog are only removed when pruning', function () {
    PermissionModel::query()->create(['name' => 'legacy.permission', 'guard_name' => 'web']);

    $this->artisan('app:sync-permissions')->expectsOutputToContain('usa --prune')->assertSuccessful();
    expect(PermissionModel::query()->where('name', 'legacy.permission')->exists())->toBeTrue();

    $this->artisan('app:sync-permissions', ['--prune' => true])->assertSuccessful();
    expect(PermissionModel::query()->where('name', 'legacy.permission')->exists())->toBeFalse();
});

test('the initial roles are created only on an installation without roles', function () {
    Role::query()->delete();

    $this->artisan('app:sync-permissions')->expectsOutputToContain('Roles iniciales creados')->assertSuccessful();

    expect(Role::query()->where('is_root', true)->sole()->label)->toBe('Superadministrador')
        ->and(Role::query()->count())->toBe(4);
});

test('newly created permissions are granted only to the root role', function () {
    $newPermissions = [Permission::ViewConnections->value, Permission::ManageTags->value];
    PermissionModel::query()->whereIn('name', $newPermissions)->delete();
    $supervisorBefore = role(DefaultRole::Supervisor)->load('permissions')->permissionNames();
    $agentBefore = role(DefaultRole::Agent)->load('permissions')->permissionNames();

    $this->artisan('app:sync-permissions')
        ->expectsOutputToContain('Permisos nuevos otorgados a la autoridad raíz: Superadministrador.')
        ->assertSuccessful();

    expect(role(DefaultRole::SuperAdministrator)->load('permissions')->permissionNames())->toContain(...$newPermissions)
        ->and(role(DefaultRole::Supervisor)->load('permissions')->permissionNames())->toEqualCanonicalizing($supervisorBefore)
        ->and(role(DefaultRole::Agent)->load('permissions')->permissionNames())->toEqualCanonicalizing($agentBefore);
});

test('permissions the root role already lacked are not granted again', function () {
    $admin = role(DefaultRole::SuperAdministrator);
    $admin->revokePermissionTo(Permission::ExportAuditLog->value);

    $this->artisan('app:sync-permissions')->assertSuccessful();

    expect($admin->refresh()->load('permissions')->permissionNames())->not->toContain(Permission::ExportAuditLog->value);
});
