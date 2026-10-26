<?php

use App\Enums\DataScope;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/**
 * A delegated administrator: manages users and roles of their departments but
 * is not the root authority.
 */
function delegatedAdministrator(?Department $department = null): User
{
    $role = Role::factory()->scope(DataScope::Departments)->withPermissions([
        Permission::ViewUsers,
        Permission::CreateUsers,
        Permission::UpdateUsers,
        Permission::DeleteUsers,
        Permission::AssignRoles,
        Permission::ViewDepartments,
        Permission::ManageDepartmentMembers,
        Permission::ViewConversations,
        Permission::ReplyConversations,
    ])->create(['label' => 'Coordinador']);

    return User::factory()->withRole($role)->inDepartments($department ?? Department::factory()->create())->create();
}

test('nobody can assign a role with more privileges than their own', function () {
    $department = Department::factory()->create();
    $coordinator = delegatedAdministrator($department);

    // The root role does not exist for them, so it is refused as an unknown role.
    $this->actingAs($coordinator)->post(route('admin.users.store'), [
        'name' => 'Escalado',
        'email' => 'escalado@empresa.test',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role_id' => role(DefaultRole::SuperAdministrator)->id,
        'department_ids' => [$department->id],
    ])->assertSessionHasErrors(['role_id' => 'El valor seleccionado en rol no es válido.']);

    $this->actingAs($coordinator)->post(route('admin.users.store'), [
        'name' => 'Agente Nuevo',
        'email' => 'agente-nuevo@empresa.test',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role_id' => role(DefaultRole::Agent)->id,
        'department_ids' => [$department->id],
    ])->assertSessionHasErrors(['role_id']);
});

test('roles below the actor can be assigned within the actor departments only', function () {
    $department = Department::factory()->create();
    $coordinator = delegatedAdministrator($department);
    $basic = Role::factory()->withPermissions([Permission::ViewConversations, Permission::ReplyConversations])->create();
    $foreign = Department::factory()->create();

    $this->actingAs($coordinator)->post(route('admin.users.store'), [
        'name' => 'Fuera de Alcance',
        'email' => 'fuera@empresa.test',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role_id' => $basic->id,
        'department_ids' => [$foreign->id],
    ])->assertSessionHasErrors('department_ids.0');

    $this->actingAs($coordinator)->post(route('admin.users.store'), [
        'name' => 'Dentro de Alcance',
        'email' => 'dentro@empresa.test',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role_id' => $basic->id,
        'department_ids' => [$department->id],
    ])->assertSessionHasNoErrors();
});

test('nobody can grant permissions or scope they do not hold', function () {
    $coordinator = delegatedAdministrator();
    $coordinator->role()?->givePermissionTo(Permission::CreateRoles->value);

    // Creating roles is an organization-wide capability, so a departmental role never exercises it.
    $this->actingAs($coordinator->refresh())->post(route('admin.roles.store'), [
        'label' => 'Atajo',
        'scope' => 'global',
        'permissions' => ['settings.update'],
    ])->assertForbidden();
});

test('a role manager cannot grant more than they hold nor edit their own role', function () {
    $managerRole = Role::factory()->scope(DataScope::Global)->withPermissions([
        Permission::ViewRoles,
        Permission::CreateRoles,
        Permission::UpdateRoles,
        Permission::ViewUsers,
    ])->create(['label' => 'Gestor de Roles']);
    $manager = User::factory()->withRole($managerRole)->create();

    $this->actingAs($manager)->post(route('admin.roles.store'), [
        'label' => 'Superpoderes',
        'scope' => 'global',
        'permissions' => ['users.view', 'settings.update'],
    ])->assertSessionHasErrors(['permissions' => 'Solo puedes otorgar permisos que tú mismo tienes: Modificar configuración.']);

    $this->actingAs($manager)->put(route('admin.roles.update', $managerRole), [
        'label' => 'Gestor de Roles',
        'scope' => 'global',
        'permissions' => ['users.view', 'roles.view', 'roles.create', 'roles.update', 'settings.update'],
    ])->assertForbidden();

    $this->actingAs($manager)->put(route('admin.roles.update', role(DefaultRole::SuperAdministrator)), [
        'label' => 'Administrador',
        'scope' => 'global',
        'permissions' => array_column(Permission::rootCapabilities(), 'value'),
    ])->assertNotFound();

    expect($managerRole->refresh()->load('permissions')->grants(Permission::UpdateSettings))->toBeFalse();
});

test('a delegated administrator cannot manage peers, superiors or users outside their departments', function () {
    $department = Department::factory()->create();
    $coordinator = delegatedAdministrator($department);
    $peer = User::factory()->withRole($coordinator->role())->inDepartments($department)->create();
    $root = User::factory()->superAdministrator()->create();
    $outsider = User::factory()->agent()->inDepartments(Department::factory()->create())->create();

    foreach ([$peer, $coordinator] as $target) {
        $this->actingAs($coordinator)
            ->delete(route('admin.users.destroy', $target))
            ->assertForbidden();
    }

    // Accounts outside the coordinator's departments answer like ids that do not exist.
    foreach ([$root, $outsider] as $target) {
        $this->actingAs($coordinator)
            ->delete(route('admin.users.destroy', $target))
            ->assertNotFound();
    }

    expect($peer->refresh()->is_active)->toBeTrue()
        ->and($root->refresh()->is_active)->toBeTrue()
        ->and($outsider->refresh()->is_active)->toBeTrue();
});

test('the user list only shows users within the actor scope', function () {
    $department = Department::factory()->create();
    $coordinator = delegatedAdministrator($department);
    User::factory()->agent()->inDepartments($department)->create(['name' => 'Compañera']);
    User::factory()->agent()->inDepartments(Department::factory()->create())->create(['name' => 'Ajena']);

    $this->actingAs($coordinator)
        ->get(route('admin.users.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('users.data', 2)
            ->where('stats.active', 2)
            ->where('can.create', true)
        );
});

test('a custom role with a narrow scope never reaches every conversation', function () {
    $department = Department::factory()->create();
    $coordinator = delegatedAdministrator($department);
    $own = Conversation::factory()->inDepartment($department)->create();
    $foreign = Conversation::factory()->inDepartment(Department::factory()->create())->create();

    expect(Conversation::query()->visibleTo($coordinator)->pluck('id')->all())->toBe([$own->id])
        ->and($coordinator->can('view', $foreign))->toBeFalse();
});
