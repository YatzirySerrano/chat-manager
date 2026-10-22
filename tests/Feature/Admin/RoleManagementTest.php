<?php

use App\Actions\Roles\SaveRole;
use App\Enums\AuditAction;
use App\Enums\DataScope;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Enums\PermissionGroup;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

test('users without the permission cannot see roles', function (DefaultRole $role) {
    $this->actingAs(User::factory()->withRole($role)->create())
        ->get(route('admin.roles.index'))
        ->assertForbidden();
})->with(['supervisor' => DefaultRole::Supervisor, 'agent' => DefaultRole::Agent]);

test('administrators see every role with its users and the permission catalog', function () {
    $admin = User::factory()->superAdministrator()->create();
    User::factory()->agent()->count(2)->create();

    $this->actingAs($admin)
        ->get(route('admin.roles.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/roles/Index')
            ->has('roles', 4)
            ->where('roles.0.label', 'Superadministrador')
            ->where('roles.0.is_root', true)
            ->where('roles.0.can.delete', false)
            ->where('roles.0.can.restore', false)
            ->where('roles.1.label', 'Administrador')
            ->where('roles.1.is_root', false)
            ->where('roles.2.label', 'Agente')
            ->where('roles.2.users_count', 2)
            ->has('catalog', count(PermissionGroup::cases()))
            ->where('can.create', true)
        );
});

test('administrators can create a custom role with grouped permissions', function () {
    $admin = User::factory()->superAdministrator()->create();

    $this->actingAs($admin)
        ->post(route('admin.roles.store'), [
            'label' => 'Coordinador de Atención',
            'description' => 'Coordina a los equipos de atención.',
            'scope' => 'departments',
            'permissions' => ['dashboard.view', 'departments.view', 'departments.manage-members', 'users.view'],
        ])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Rol Coordinador de Atención creado.');

    $role = Role::query()->where('label', 'Coordinador de Atención')->sole();

    expect($role)
        ->name->toBe('coordinador-de-atencion')
        ->scope->toBe(DataScope::Departments)
        ->is_root->toBeFalse()
        ->and($role->permissionNames())->toEqualCanonicalizing(['dashboard.view', 'departments.view', 'departments.manage-members', 'users.view']);

    expect(AuditLog::query()->where('action', AuditAction::RoleCreated)->sole()->auditable_id)->toBe($role->id);
});

test('role names must be unique and permissions must exist and be available', function () {
    $admin = User::factory()->superAdministrator()->create();

    $this->actingAs($admin)
        ->post(route('admin.roles.store'), [
            'label' => 'Agente',
            'scope' => 'assigned',
            'permissions' => ['invented.permission', 'reports.view'],
        ])
        ->assertSessionHasErrors(['label', 'permissions.0'])
        // reports.view is available since increment F (reports module).
        ->assertSessionDoesntHaveErrors(['permissions.1']);
});

test('organization-wide permissions require the organization scope', function () {
    $admin = User::factory()->superAdministrator()->create();

    $this->actingAs($admin)
        ->post(route('admin.roles.store'), [
            'label' => 'Responsable de Marca',
            'scope' => 'departments',
            'permissions' => ['appearance.view', 'appearance.update'],
        ])
        ->assertSessionHasErrors(['permissions' => 'Estos permisos requieren el alcance «Toda la organización»: Ver personalización, Modificar la apariencia global.']);
});

test('editing a role replaces its permissions, keeps future ones and is audited', function () {
    // Every permission is available since increment F, so the editable set
    // is the whole role and reports.view is kept because it is resubmitted.
    $admin = User::factory()->superAdministrator()->create();
    $supervisor = role(DefaultRole::Supervisor);
    $editable = array_values(array_filter(
        $supervisor->permissionNames(),
        fn (string $name): bool => Permission::from($name)->isAvailable(),
    ));

    $this->actingAs($admin)
        ->put(route('admin.roles.update', $supervisor), [
            'label' => 'Supervisión de Atención',
            'description' => $supervisor->description,
            'scope' => 'departments',
            'permissions' => [...$editable, 'audit.view'],
        ])
        ->assertSessionHasNoErrors();

    $supervisor->refresh()->load('permissions');

    expect($supervisor->label)->toBe('Supervisión de Atención')
        ->and($supervisor->permissionNames())->toContain('audit.view', 'reports.view', 'conversations.reply');

    $log = AuditLog::query()->where('action', AuditAction::RolePermissionsChanged)->sole();

    expect($log->properties)->toMatchArray(['added' => ['audit.view'], 'removed' => []]);
    expect(AuditLog::query()->where('action', AuditAction::RoleUpdated)->sole()->properties['changes'])
        ->toHaveKey('label');
});

test('the root role always keeps the capabilities to manage users and roles', function () {
    $admin = User::factory()->superAdministrator()->create();
    $root = role(DefaultRole::SuperAdministrator);

    $this->actingAs($admin)
        ->put(route('admin.roles.update', $root), [
            'label' => 'Superadministrador',
            'scope' => 'departments',
            'permissions' => ['dashboard.view'],
        ])
        ->assertSessionHasErrors(['scope', 'permissions']);

    $this->actingAs($admin)->delete(route('admin.roles.destroy', $root))->assertForbidden();

    expect($root->refresh()->is_active)->toBeTrue();
});

test('the root role can drop ordinary permissions', function () {
    $admin = User::factory()->superAdministrator()->create();
    $root = role(DefaultRole::SuperAdministrator);
    $kept = [...array_column(Permission::rootCapabilities(), 'value'), 'dashboard.view'];

    $this->actingAs($admin)
        ->put(route('admin.roles.update', $root), ['label' => 'Superadministrador', 'scope' => 'global', 'permissions' => $kept])
        ->assertSessionHasNoErrors();

    expect($admin->refresh()->holdsPermission(Permission::UpdateSettings))->toBeFalse()
        ->and($admin->holdsPermission(Permission::AssignRoles))->toBeTrue();
});

test('a deleted role grants nothing to whoever still holds it', function () {
    $role = Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewAuditLog])->create();
    $auditor = User::factory()->withRole($role)->create();

    $this->actingAs($auditor)->get(route('admin.audit.index'))->assertOk();

    $role->forceFill(['is_active' => false])->save();

    $this->actingAs($auditor->refresh())->get(route('admin.audit.index'))->assertForbidden();
});

test('deleting an unused role keeps its definition and restoring it brings it back', function () {
    $admin = User::factory()->superAdministrator()->create();
    $role = Role::factory()->withPermissions([Permission::ViewDashboard])->create(['label' => 'Temporal']);

    $this->actingAs($admin)->delete(route('admin.roles.destroy', $role))->assertSessionHasNoErrors();

    $this->assertModelExists($role);
    expect($role->refresh()->isDeleted())->toBeTrue()
        ->and($role->load('permissions')->permissionNames())->toBe(['dashboard.view'])
        ->and(AuditLog::query()->where('action', AuditAction::RoleDeleted)->sole()->properties)->toBe(['permissions' => ['dashboard.view']]);

    $this->actingAs($admin)
        ->get(route('admin.roles.index'))
        ->assertInertia(fn (Assert $page) => $page->has('roles', 4));

    $this->actingAs($admin)
        ->get(route('admin.roles.index', ['record' => 'deleted']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('roles', 1)
            ->where('roles.0.label', 'Temporal')
            ->where('roles.0.can.restore', true)
            ->where('roles.0.can.update', false));

    $this->actingAs($admin)->patch(route('admin.roles.restore', $role))->assertSessionHasNoErrors();

    expect($role->refresh()->is_active)->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::RoleRestored)->where('auditable_id', $role->id)->exists())->toBeTrue();
});

test('without records.view-deleted deleted roles are neither listed nor restorable', function () {
    $manager = User::factory()->withRole(Role::factory()->scope(DataScope::Global)->withPermissions([
        Permission::ViewRoles, Permission::UpdateRoles, Permission::DeleteRoles,
    ])->create())->create();
    $deleted = Role::factory()->inactive()->create();

    $this->actingAs($manager)
        ->get(route('admin.roles.index', ['record' => 'deleted']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.record', 'active')
            ->where('recordStates', [])
            ->where('roles', fn ($roles) => collect($roles)->doesntContain('id', $deleted->id)));

    $this->actingAs($manager)->patch(route('admin.roles.restore', $deleted))->assertForbidden();

    expect($deleted->refresh()->is_active)->toBeFalse();
});

test('roles in use cannot be deleted until their users are reassigned', function () {
    $admin = User::factory()->superAdministrator()->create();
    $role = Role::factory()->create(['label' => 'Analista de Reportes']);
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($admin)
        ->delete(route('admin.roles.destroy', $role))
        ->assertSessionHasErrors(['role' => 'El rol Analista de Reportes está asignado a 1 usuario activo. Asígnales otro rol antes de eliminarlo.']);

    $user->forceFill(['is_active' => false])->save();

    $this->actingAs($admin)
        ->delete(route('admin.roles.destroy', $role))
        ->assertSessionHasErrors(['role' => 'El rol Analista de Reportes está asignado a 1 usuario eliminado. Asígnales otro rol antes de eliminarlo.']);

    expect($role->refresh()->is_active)->toBeTrue();

    $user->syncRoles([role(DefaultRole::Agent)]);

    $this->actingAs($admin)->delete(route('admin.roles.destroy', $role))->assertSessionHasNoErrors();

    $this->assertModelExists($role);
    expect($role->refresh()->isDeleted())->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::RoleDeleted)->sole()->auditable_label)->toBe('Analista de Reportes');
});

test('a role name taken by a simultaneous request is reported as a validation error', function () {
    $admin = User::factory()->superAdministrator()->create();
    Role::factory()->create(['label' => 'Responsable de Calidad']);

    expect(fn () => app(SaveRole::class)->handle(null, [
        'label' => 'Responsable de Calidad',
        'description' => null,
        'scope' => DataScope::Assigned,
        'permissions' => [],
    ], $admin))->toThrow(ValidationException::class, 'Ya existe un rol con ese nombre.');
});

test('saving a role without effective changes writes nothing and says so', function () {
    $admin = User::factory()->superAdministrator()->create();
    $role = Role::factory()->scope(DataScope::Departments)
        ->withPermissions([Permission::ViewDashboard, Permission::ViewConversations, Permission::ViewContacts])
        ->create(['label' => 'Coordinación', 'description' => null]);
    $updatedAt = $role->updated_at;
    $this->travel(1)->hours();

    $writes = databaseWritesDuring(fn () => $this->actingAs($admin)
        ->put(route('admin.roles.update', $role), [
            'label' => ' Coordinación ',
            'description' => '  ',
            'scope' => 'departments',
            'permissions' => ['contacts.view', 'dashboard.view', 'conversations.view'],
        ])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'No había cambios por guardar.'])
        ->assertInertiaFlashMissing('highlight'));

    expect($writes)->toBe([])
        ->and($role->refresh()->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and(AuditLog::query()->whereIn('action', [AuditAction::RoleUpdated, AuditAction::RolePermissionsChanged])->exists())->toBeFalse();
});

test('changing only the permissions of a role is saved and audited', function () {
    $admin = User::factory()->superAdministrator()->create();
    $role = Role::factory()->scope(DataScope::Departments)
        ->withPermissions([Permission::ViewDashboard])
        ->create(['label' => 'Coordinación']);

    $this->actingAs($admin)
        ->put(route('admin.roles.update', $role), [
            'label' => 'Coordinación',
            'description' => $role->description,
            'scope' => 'departments',
            'permissions' => ['dashboard.view', 'contacts.view'],
        ])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Rol Coordinación actualizado.']);

    expect($role->refresh()->permissionNames())->toContain('contacts.view')
        ->and(AuditLog::query()->where('action', AuditAction::RolePermissionsChanged)->sole()->properties['added'])->toBe(['contacts.view']);
});
