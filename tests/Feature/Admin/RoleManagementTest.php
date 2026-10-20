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
    $admin = User::factory()->administrator()->create();
    User::factory()->agent()->count(2)->create();

    $this->actingAs($admin)
        ->get(route('admin.roles.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/roles/Index')
            ->has('roles', 3)
            ->where('roles.0.label', 'Administrador')
            ->where('roles.0.is_root', true)
            ->where('roles.0.can.delete', false)
            ->where('roles.0.can.changeStatus', false)
            ->where('roles.1.label', 'Agente')
            ->where('roles.1.users_count', 2)
            ->has('catalog', count(PermissionGroup::cases()))
            ->where('can.create', true)
        );
});

test('administrators can create a custom role with grouped permissions', function () {
    $admin = User::factory()->administrator()->create();

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
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)
        ->post(route('admin.roles.store'), [
            'label' => 'Agente',
            'scope' => 'assigned',
            'permissions' => ['invented.permission', 'conversations.reply'],
        ])
        ->assertSessionHasErrors(['label', 'permissions.0', 'permissions.1']);
});

test('organization-wide permissions require the organization scope', function () {
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)
        ->post(route('admin.roles.store'), [
            'label' => 'Responsable de Marca',
            'scope' => 'departments',
            'permissions' => ['appearance.view', 'appearance.update'],
        ])
        ->assertSessionHasErrors(['permissions' => 'Estos permisos requieren el alcance «Toda la organización»: Ver personalización, Modificar la apariencia global.']);
});

test('editing a role replaces its permissions, keeps future ones and is audited', function () {
    $admin = User::factory()->administrator()->create();
    $agent = role(DefaultRole::Agent);

    $this->actingAs($admin)
        ->put(route('admin.roles.update', $agent), [
            'label' => 'Agente de Atención',
            'description' => $agent->description,
            'scope' => 'assigned',
            'permissions' => ['dashboard.view', 'contacts.view', 'quick-replies.use', 'audit.view'],
        ])
        ->assertSessionHasNoErrors();

    $agent->refresh()->load('permissions');

    expect($agent->label)->toBe('Agente de Atención')
        ->and($agent->permissionNames())->toContain('audit.view', 'conversations.reply', 'conversations.view');

    $log = AuditLog::query()->where('action', AuditAction::RolePermissionsChanged)->sole();

    expect($log->properties)->toMatchArray(['added' => ['audit.view'], 'removed' => []]);
    expect(AuditLog::query()->where('action', AuditAction::RoleUpdated)->sole()->properties['changes'])
        ->toHaveKey('label');
});

test('the root role always keeps the capabilities to manage users and roles', function () {
    $admin = User::factory()->administrator()->create();
    $root = role(DefaultRole::Administrator);

    $this->actingAs($admin)
        ->put(route('admin.roles.update', $root), [
            'label' => 'Administrador',
            'scope' => 'departments',
            'permissions' => ['dashboard.view'],
        ])
        ->assertSessionHasErrors(['scope', 'permissions']);

    $this->actingAs($admin)
        ->patch(route('admin.roles.status.update', $root), ['is_active' => false])
        ->assertForbidden();

    $this->actingAs($admin)->delete(route('admin.roles.destroy', $root))->assertForbidden();

    expect($root->refresh()->is_active)->toBeTrue();
});

test('the root role can drop ordinary permissions', function () {
    $admin = User::factory()->administrator()->create();
    $root = role(DefaultRole::Administrator);
    $kept = [...array_column(Permission::rootCapabilities(), 'value'), 'dashboard.view'];

    $this->actingAs($admin)
        ->put(route('admin.roles.update', $root), ['label' => 'Administrador', 'scope' => 'global', 'permissions' => $kept])
        ->assertSessionHasNoErrors();

    expect($admin->refresh()->holdsPermission(Permission::UpdateSettings))->toBeFalse()
        ->and($admin->holdsPermission(Permission::AssignRoles))->toBeTrue();
});

test('deactivating a role removes the permissions of its users immediately', function () {
    $admin = User::factory()->administrator()->create();
    $role = Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewAuditLog])->create();
    $auditor = User::factory()->withRole($role)->create();

    $this->actingAs($auditor)->get(route('admin.audit.index'))->assertOk();

    $this->actingAs($admin)
        ->patch(route('admin.roles.status.update', $role), ['is_active' => false])
        ->assertSessionHasNoErrors();

    expect($role->refresh()->is_active)->toBeFalse()
        ->and(AuditLog::query()->where('action', AuditAction::RoleDeactivated)->sole()->properties)->toBe(['users_affected' => 1]);

    $this->actingAs($auditor->refresh())->get(route('admin.audit.index'))->assertForbidden();
});

test('roles in use cannot be deleted until their users are reassigned', function () {
    $admin = User::factory()->administrator()->create();
    $role = Role::factory()->create(['label' => 'Analista de Reportes']);
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($admin)
        ->delete(route('admin.roles.destroy', $role))
        ->assertSessionHasErrors(['role' => 'El rol Analista de Reportes está asignado a 1 usuario. Asígnales otro rol antes de eliminarlo.']);

    $this->assertModelExists($role);

    $user->syncRoles([role(DefaultRole::Agent)]);

    $this->actingAs($admin)->delete(route('admin.roles.destroy', $role))->assertSessionHasNoErrors();

    $this->assertModelMissing($role);
    expect(AuditLog::query()->where('action', AuditAction::RoleDeleted)->sole()->properties['label'])->toBe('Analista de Reportes');
});

test('a role name taken by a simultaneous request is reported as a validation error', function () {
    $admin = User::factory()->administrator()->create();
    Role::factory()->create(['label' => 'Responsable de Calidad']);

    expect(fn () => app(SaveRole::class)->handle(null, [
        'label' => 'Responsable de Calidad',
        'description' => null,
        'scope' => DataScope::Assigned,
        'permissions' => [],
    ], $admin))->toThrow(ValidationException::class, 'Ya existe un rol con ese nombre.');
});
