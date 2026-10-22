<?php

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Connection;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

test('users without the connections permissions cannot reach any connection endpoint', function (Closure $makeUser) {
    Http::preventStrayRequests();
    $user = $makeUser();
    $connection = Connection::factory()->inactive()->create(['name' => 'Ventas']);

    $this->actingAs($user)->get(route('connections.index'))->assertForbidden();
    $this->actingAs($user)->get(route('connections.create'))->assertForbidden();
    $this->actingAs($user)->get(route('connections.show', $connection))->assertForbidden();
    $this->actingAs($user)->post(route('connections.store'), ['name' => 'Nueva', 'department_ids' => []])->assertForbidden();
    $this->actingAs($user)->put(route('connections.update', $connection), ['name' => 'Renombrada', 'department_ids' => []])->assertForbidden();
    $this->actingAs($user)->patch(route('connections.status.update', $connection), ['is_active' => true])->assertForbidden();
    $this->actingAs($user)->post(route('connections.verify', $connection))->assertForbidden();
    $this->actingAs($user)->delete(route('connections.destroy', $connection))->assertForbidden();

    expect(Connection::query()->count())->toBe(1)
        ->and($connection->refresh())->name->toBe('Ventas')->is_active->toBeFalse();
})->with([
    'agent' => [fn () => User::factory()->agent()->create()],
    'supervisor' => [fn () => User::factory()->supervisor()->create()],
    'custom global role without connection permissions' => [fn () => User::factory()
        ->withRole(Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewUsers, Permission::ViewSettings])->create())
        ->create()],
]);

test('connection permissions are ignored for roles without organization-wide scope', function () {
    $role = Role::factory()->scope(DataScope::Departments)->withPermissions([
        Permission::ViewConnections,
        Permission::ManageConnections,
    ])->create();
    $user = User::factory()->withRole($role)->create();
    $connection = Connection::factory()->create();

    $this->actingAs($user)->get(route('connections.index'))->assertForbidden();
    $this->actingAs($user)->put(route('connections.update', $connection), ['name' => 'Renombrada', 'department_ids' => []])->assertForbidden();
});

test('viewers can consult connections but not change them', function () {
    Http::preventStrayRequests();
    $role = Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewConnections])->create();
    $viewer = User::factory()->withRole($role)->create();
    $connection = Connection::factory()->create(['name' => 'Ventas']);

    $this->actingAs($viewer)
        ->get(route('connections.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('connections/Index')
            ->has('connections', 1)
            ->where('can.create', false)
            ->where('can.manage', false));

    $this->actingAs($viewer)
        ->get(route('connections.show', $connection))
        ->assertInertia(fn (Assert $page) => $page
            ->component('connections/Form')
            ->where('connection.name', 'Ventas')
            ->where('can', ['update' => false, 'changeStatus' => false, 'verify' => false, 'delete' => false, 'restore' => false, 'syncTemplates' => false, 'viewTemplates' => false]));

    $this->actingAs($viewer)->get(route('connections.create'))->assertForbidden();
    $this->actingAs($viewer)->post(route('connections.store'), ['name' => 'Nueva', 'department_ids' => []])->assertForbidden();
    $this->actingAs($viewer)->put(route('connections.update', $connection), ['name' => 'Renombrada', 'department_ids' => []])->assertForbidden();
    $this->actingAs($viewer)->patch(route('connections.status.update', $connection), ['is_active' => false])->assertForbidden();
    $this->actingAs($viewer)->post(route('connections.verify', $connection))->assertForbidden();
    $this->actingAs($viewer)->delete(route('connections.destroy', $connection))->assertForbidden();

    expect($connection->refresh())->name->toBe('Ventas')->is_active->toBeTrue()->deleted_at->toBeNull();
});

test('a custom global role with the manage permission can administer connections', function () {
    $role = Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ManageConnections])->create();
    $manager = User::factory()->withRole($role)->create();

    $this->actingAs($manager)->get(route('connections.index'))->assertOk();
    $this->actingAs($manager)
        ->post(route('connections.store'), ['name' => 'Soporte', 'department_ids' => []])
        ->assertSessionHasNoErrors();

    expect(Connection::query()->where('name', 'Soporte')->exists())->toBeTrue();
});

test('guests are redirected to the login', function () {
    $this->get(route('connections.index'))->assertRedirect(route('login'));
});

test('each listed connection exposes only the operations the viewer may perform', function () {
    Connection::factory()->create();
    $viewer = User::factory()->withRole(
        Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewConnections])->create()
    )->create();
    $manager = User::factory()->withRole(
        Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ManageConnections])->create()
    )->create();

    $this->actingAs($viewer)
        ->get(route('connections.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('connections.0.can', ['view' => true, 'update' => false, 'changeStatus' => false, 'verify' => false, 'delete' => false, 'restore' => false]));

    $this->actingAs($manager)
        ->get(route('connections.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('connections.0.can', ['view' => true, 'update' => true, 'changeStatus' => true, 'verify' => true, 'delete' => true, 'restore' => false]));
});
