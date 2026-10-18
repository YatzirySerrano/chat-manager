<?php

use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\Role;
use Spatie\Permission\Models\Permission as PermissionModel;

test('synchronizing never overwrites roles customized from the interface', function () {
    $agent = role(DefaultRole::Agent);
    $agent->syncPermissions(['dashboard.view', 'audit.view']);
    $agent->forceFill(['label' => 'Agente Senior', 'is_active' => false])->save();
    role(DefaultRole::Supervisor)->delete();

    $this->artisan('app:sync-permissions')->assertSuccessful();

    $agent->refresh()->load('permissions');

    expect($agent->label)->toBe('Agente Senior')
        ->and($agent->is_active)->toBeFalse()
        ->and($agent->permissionNames())->toEqualCanonicalizing(['dashboard.view', 'audit.view'])
        ->and(Role::query()->where('name', 'supervisor')->exists())->toBeFalse();
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

    expect(Role::query()->where('is_root', true)->sole()->label)->toBe('Administrador')
        ->and(Role::query()->count())->toBe(3);
});
