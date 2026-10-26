<?php

use App\Enums\DataScope;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array<string, mixed>
 */
function newUserInput(Role $role, string $email = 'nuevo@empresa.test'): array
{
    return ['name' => 'Nuevo', 'email' => $email, 'password' => 'password', 'password_confirmation' => 'password', 'role_id' => $role->id];
}

test('the administrator role reaches the whole organization with every permission except the reserved ones', function () {
    $administrator = User::factory()->administrator()->create();

    expect($administrator->hasRootAuthority())->toBeFalse()
        ->and($administrator->dataScope())->toBe(DataScope::Global)
        ->and($administrator->holdsPermission(Permission::DeleteUsers))->toBeTrue()
        ->and($administrator->holdsPermission(Permission::ViewDeletedRecords))->toBeTrue()
        ->and(role(DefaultRole::Administrator)->load('permissions')->grants(Permission::ImportContacts))->toBeFalse();

    $this->actingAs($administrator)
        ->post(route('admin.users.store'), newUserInput(role(DefaultRole::Agent)))
        ->assertSessionHasNoErrors();
});

test('an administrator cannot create superadministrators nor other administrators', function () {
    $administrator = User::factory()->administrator()->create();

    $this->actingAs($administrator)
        ->post(route('admin.users.store'), newUserInput(role(DefaultRole::SuperAdministrator)))
        ->assertSessionHasErrors('role_id');

    $this->actingAs($administrator)
        ->post(route('admin.users.store'), newUserInput(role(DefaultRole::Administrator), 'par@empresa.test'))
        ->assertSessionHasErrors('role_id');

    expect(User::query()->whereIn('email', ['nuevo@empresa.test', 'par@empresa.test'])->exists())->toBeFalse();

    $this->actingAs($administrator)
        ->get(route('admin.users.index'))
        ->assertInertia(fn (Assert $page) => $page->where('roles', fn ($roles) => collect($roles)->where('is_root', true)->isEmpty()));
});

test('an administrator cannot elevate anybody, themselves included, to the root role', function () {
    $administrator = User::factory()->administrator()->create();
    $agent = User::factory()->agent()->create();
    $root = role(DefaultRole::SuperAdministrator);

    // The root role does not exist for them, so it is refused as an unknown role.
    $this->actingAs($administrator)
        ->put(route('admin.users.update', $agent), ['name' => $agent->name, 'email' => $agent->email, 'role_id' => $root->id])
        ->assertSessionHasErrors(['role_id' => 'El valor seleccionado en rol no es válido.']);

    $this->actingAs($administrator)
        ->put(route('admin.users.update', $administrator), ['name' => $administrator->name, 'email' => $administrator->email, 'role_id' => $root->id])
        ->assertForbidden();

    expect($agent->refresh()->hasRootAuthority())->toBeFalse()
        ->and($administrator->refresh()->hasRootAuthority())->toBeFalse();
});

test('an administrator cannot edit, delete or restore the root role', function () {
    $administrator = User::factory()->administrator()->create();
    $root = role(DefaultRole::SuperAdministrator);

    $this->actingAs($administrator)
        ->put(route('admin.roles.update', $root), ['label' => 'Tomado', 'scope' => 'global', 'permissions' => []])
        ->assertNotFound();
    $this->actingAs($administrator)->delete(route('admin.roles.destroy', $root))->assertNotFound();
    $this->actingAs($administrator)->patch(route('admin.roles.restore', $root))->assertNotFound();

    expect($root->refresh()->label)->toBe('Superadministrador');
});

test('reserved permissions are never granted to another role, not even by a superadministrator', function () {
    $superAdministrator = User::factory()->superAdministrator()->create();
    $message = 'Estos permisos no pueden otorgarse a este rol: Importar contactos desde Excel/CSV.';

    $this->actingAs($superAdministrator)
        ->post(route('admin.roles.store'), ['label' => 'Importadores', 'scope' => 'global', 'permissions' => ['contacts.import']])
        ->assertSessionHasErrors(['permissions' => $message]);

    $this->actingAs($superAdministrator)
        ->put(route('admin.roles.update', role(DefaultRole::Administrator)), ['label' => 'Administrador', 'scope' => 'global', 'permissions' => ['contacts.import']])
        ->assertSessionHasErrors(['permissions' => $message]);

    $this->actingAs($superAdministrator)
        ->get(route('admin.roles.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('grantablePermissions', fn ($permissions) => ! collect($permissions)->contains('contacts.import'))
            ->where('catalog', fn ($catalog) => collect($catalog)->flatMap(fn ($group) => $group['permissions'])->firstWhere('value', 'contacts.import')['reserved'] === true));

    expect(Role::query()->where('label', 'Importadores')->exists())->toBeFalse()
        ->and(role(DefaultRole::Administrator)->load('permissions')->grants(Permission::ImportContacts))->toBeFalse();
});

test('the root role keeps its reserved permissions when it is edited', function () {
    $superAdministrator = User::factory()->superAdministrator()->create();
    $root = role(DefaultRole::SuperAdministrator);

    $this->actingAs($superAdministrator)
        ->put(route('admin.roles.update', $root), [
            'label' => 'Superadministrador',
            'scope' => 'global',
            'permissions' => array_column(Permission::rootCapabilities(), 'value'),
        ])
        ->assertSessionHasNoErrors();

    expect($root->refresh()->load('permissions')->grants(Permission::ImportContacts))->toBeTrue()
        ->and($superAdministrator->refresh()->holdsPermission(Permission::ImportContacts))->toBeTrue();
});

test('a reserved permission stored on another role is never exercised', function () {
    $role = Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewContacts, Permission::ImportContacts])->create();
    $user = User::factory()->withRole($role)->create();

    expect($user->holdsPermission(Permission::ImportContacts))->toBeFalse()
        ->and($user->effectivePermissions())->not->toContain(Permission::ImportContacts->value);
});
