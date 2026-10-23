<?php

use App\Enums\AuditAction;
use App\Enums\DataScope;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Support\ManualValidation;
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

/**
 * Leave a default role with only the given permissions, as an installation
 * created before later increments would have it.
 *
 * @param  list<string>  $permissions
 */
function outdatedRole(DefaultRole $default, array $permissions): Role
{
    $role = role($default);
    $role->syncPermissions($permissions);

    return $role->refresh()->load('permissions');
}

test('upgrading the default roles only previews the changes by default', function () {
    $agent = outdatedRole(DefaultRole::Agent, ['dashboard.view', 'conversations.view']);

    $this->artisan('app:sync-permissions', ['--upgrade-default-roles' => true])
        ->expectsOutputToContain('Agente (agent')
        ->expectsOutputToContain('team-chat.use')
        ->expectsOutputToContain('Vista previa: no se modificó nada.')
        ->assertSuccessful();

    expect($agent->refresh()->load('permissions')->permissionNames())->toEqualCanonicalizing(['dashboard.view', 'conversations.view'])
        ->and(AuditLog::query()->count())->toBe(0);
});

test('applying the upgrade adds what is missing, keeps customizations, drops what the scope never exercises and is audited once', function () {
    $root = User::factory()->superAdministrator()->create();
    $agent = outdatedRole(DefaultRole::Agent, ['dashboard.view', 'audit.view', Permission::ImportContacts->value, Permission::ViewConnections->value]);
    $supervisor = outdatedRole(DefaultRole::Supervisor, ['dashboard.view']);

    $this->artisan('app:sync-permissions', ['--upgrade-default-roles' => true, '--apply' => true])->assertSuccessful();

    $permissions = $agent->refresh()->load('permissions')->permissionNames();
    $log = AuditLog::query()->where('action', AuditAction::RolePermissionsChanged)->where('auditable_id', $agent->id)->sole();

    expect($permissions)->toContain('audit.view', ...array_map(fn (Permission $permission): string => $permission->value, DefaultRole::Agent->permissions()))
        ->and($permissions)->not->toContain(Permission::ImportContacts->value, Permission::ViewConnections->value)
        ->and($log->user_id)->toBe($root->id)
        ->and($log->properties['removed'])->toEqualCanonicalizing([Permission::ImportContacts->value, Permission::ViewConnections->value])
        ->and($supervisor->refresh()->load('permissions')->permissionNames())->toContain(Permission::AssignTags->value, Permission::UseTeamChat->value)
        ->and($supervisor->permissionNames())->not->toContain(Permission::ManageTags->value);

    $audits = AuditLog::query()->count();
    $this->artisan('app:sync-permissions', ['--upgrade-default-roles' => true, '--apply' => true])->assertSuccessful();

    expect(AuditLog::query()->count())->toBe($audits);
});

test('the upgrade never touches the root role nor custom roles, and the demo role only when asked for', function () {
    User::factory()->superAdministrator()->create();
    $root = role(DefaultRole::SuperAdministrator);
    $root->revokePermissionTo(Permission::ExportAuditLog->value);
    $custom = Role::factory()->create(['label' => 'Agente nocturno', 'scope' => DataScope::Assigned]);
    $custom->syncPermissions(['dashboard.view']);
    $demo = Role::factory()->create(['label' => ManualValidation::ROLE_LABEL, 'scope' => DataScope::Assigned]);
    $demo->syncPermissions(['dashboard.view']);

    $this->artisan('app:sync-permissions', ['--upgrade-default-roles' => true, '--apply' => true])->assertSuccessful();

    expect($root->refresh()->load('permissions')->permissionNames())->not->toContain(Permission::ExportAuditLog->value)
        ->and($custom->refresh()->load('permissions')->permissionNames())->toBe(['dashboard.view'])
        ->and($demo->refresh()->load('permissions')->permissionNames())->toBe(['dashboard.view']);

    $this->artisan('app:sync-permissions', ['--upgrade-default-roles' => true, '--apply' => true, '--demo-b' => true])->assertSuccessful();

    expect($demo->refresh()->load('permissions')->permissionNames())
        ->toContain(Permission::UseTeamChat->value, Permission::AssignTags->value, Permission::ViewReports->value)
        ->not->toContain(Permission::ImportContacts->value, Permission::AssignConversations->value)
        ->and($custom->refresh()->load('permissions')->permissionNames())->toBe(['dashboard.view']);
});

test('sensitive permissions of the definition are only granted when asked for', function () {
    User::factory()->superAdministrator()->create();
    $administrator = role(DefaultRole::Administrator);
    $administrator->revokePermissionTo([Permission::ViewTemplates->value, Permission::ViewSystem->value, Permission::ManageSystem->value]);

    $this->artisan('app:sync-permissions', ['--upgrade-default-roles' => true, '--apply' => true])
        ->expectsOutputToContain('system.view, system.manage')
        ->assertSuccessful();

    expect($administrator->refresh()->load('permissions')->permissionNames())->toContain(Permission::ViewTemplates->value)
        ->not->toContain(Permission::ViewSystem->value, Permission::ManageSystem->value, Permission::ImportContacts->value);

    $this->artisan('app:sync-permissions', ['--upgrade-default-roles' => true, '--apply' => true, '--include-sensitive' => true])->assertSuccessful();

    expect($administrator->refresh()->load('permissions')->permissionNames())->toContain(Permission::ViewSystem->value, Permission::ManageSystem->value)
        ->not->toContain(Permission::ImportContacts->value);
});

test('applying the upgrade requires an active root actor', function () {
    $agent = outdatedRole(DefaultRole::Agent, ['dashboard.view']);
    $administrator = User::factory()->administrator()->create();

    $this->artisan('app:sync-permissions', ['--upgrade-default-roles' => true, '--apply' => true, '--actor' => $administrator->email])->assertFailed();
    $this->artisan('app:sync-permissions', ['--apply' => true])->assertFailed();

    expect($agent->refresh()->load('permissions')->permissionNames())->toBe(['dashboard.view']);
});
