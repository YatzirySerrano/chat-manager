<?php

use App\Enums\DataScope;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the shared permissions reflect the role currently assigned', function () {
    $supervisor = User::factory()->supervisor()->create();

    $this->actingAs($supervisor)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.role.label', 'Supervisor')
            ->where('auth.permissions', fn ($permissions) => collect($permissions)->contains('departments.manage-members')
                && ! collect($permissions)->contains('users.view'))
        );
});

test('permissions revoked from a role stop working in sessions that are already open', function () {
    $department = Department::factory()->create();
    $supervisor = User::factory()->supervisor()->inDepartments($department)->create();

    $this->actingAs($supervisor)->get(route('departments.show', $department))->assertOk();

    role(DefaultRole::Supervisor)->revokePermissionTo(Permission::ViewDepartments->value);

    $this->actingAs($supervisor->refresh())->get(route('departments.show', $department))->assertForbidden();
});

test('permissions granted from the interface apply without deploying code', function () {
    $admin = User::factory()->administrator()->create();
    $agent = User::factory()->agent()->create();

    $this->actingAs($agent)->get(route('admin.audit.index'))->assertForbidden();

    $permissions = [...role(DefaultRole::Agent)->permissionNames(), 'audit.view'];

    $this->actingAs($admin)->put(route('admin.roles.update', role(DefaultRole::Agent)), [
        'label' => 'Agente',
        'scope' => 'assigned',
        'permissions' => array_values(array_filter($permissions, fn (string $name) => Permission::from($name)->isAvailable())),
    ])->assertSessionHasNoErrors();

    $this->actingAs($agent->refresh())
        ->get(route('admin.audit.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('scope', 'own'));
});

test('an inertia visit that is no longer authorized returns to a permitted page with fresh permissions', function () {
    $admin = User::factory()->administrator()->create();
    $role = Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewUsers, Permission::UpdateUsers])->create();
    $manager = User::factory()->withRole($role)->create();
    $target = User::factory()->agent()->create();

    $role->revokePermissionTo(Permission::UpdateUsers->value);

    $this->actingAs($manager)
        ->withHeaders(['X-Inertia' => 'true'])
        ->from(route('admin.users.index'))
        ->put(route('admin.users.update', $target), ['name' => 'Cambio', 'email' => $target->email])
        ->assertRedirect(route('admin.users.index'))
        ->assertInertiaFlash('toast.type', 'error');

    expect($target->refresh()->name)->not->toBe('Cambio');
    expect($admin->hasRootAuthority())->toBeTrue();
});

test('users whose role is inactive cannot exercise any permission', function () {
    $supervisor = User::factory()->supervisor()->create();
    role(DefaultRole::Supervisor)->forceFill(['is_active' => false])->save();

    expect($supervisor->refresh()->effectivePermissions())->toBe([])
        ->and($supervisor->holdsPermission(Permission::ViewDepartments))->toBeFalse();

    $this->actingAs($supervisor)->get(route('departments.index'))->assertForbidden();
});
