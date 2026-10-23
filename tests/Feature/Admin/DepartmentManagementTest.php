<?php

use App\Enums\AuditAction;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Services\PlatformSettings;
use Inertia\Testing\AssertableInertia as Assert;

test('agents cannot access departments', function () {
    $agent = User::factory()->agent()->create();
    $department = Department::factory()->create();

    $this->actingAs($agent)->get(route('departments.index'))->assertForbidden();
    $this->actingAs($agent)->get(route('departments.show', $department))->assertForbidden();
});

test('administrators see every department', function () {
    $admin = User::factory()->superAdministrator()->create();
    Department::factory()->count(2)->create();
    Department::factory()->inactive()->create();

    $this->actingAs($admin)
        ->get(route('departments.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('departments/Index')
            ->has('departments', 2)
            ->where('filters.record', 'active')
            ->has('recordStates', 3)
            ->where('can.create', true)
        );

    $this->actingAs($admin)
        ->get(route('departments.index', ['record' => 'all']))
        ->assertInertia(fn (Assert $page) => $page->has('departments', 3));
});

test('supervisors only see the departments they belong to', function () {
    $own = Department::factory()->create(['name' => 'Soporte']);
    $other = Department::factory()->create(['name' => 'Ventas']);
    $supervisor = User::factory()->supervisor()->inDepartments($own)->create();

    $this->actingAs($supervisor)
        ->get(route('departments.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('departments', 1)
            ->where('departments.0.name', 'Soporte')
            ->where('can.create', false)
        );

    $this->actingAs($supervisor)->get(route('departments.show', $own))->assertOk();
    // Outside the supervisor's scope it answers like an id that does not exist.
    $this->actingAs($supervisor)->get(route('departments.show', $other))->assertNotFound();
});

test('administrators can create a department', function () {
    $admin = User::factory()->superAdministrator()->create();

    $this->actingAs($admin)
        ->post(route('departments.store'), [
            'name' => 'Atención a Clientes',
            'description' => 'Dudas generales',
            'color' => 'blue',
        ])
        ->assertSessionHasNoErrors();

    $department = Department::query()->where('name', 'Atención a Clientes')->sole();

    expect($department)
        ->slug->toBe('atencion-a-clientes')
        ->is_active->toBeTrue();

    expect(AuditLog::query()->where('action', AuditAction::DepartmentCreated)->sole()->auditable_id)
        ->toBe($department->id);
});

test('department names must be unique and colors valid', function () {
    $admin = User::factory()->superAdministrator()->create();
    Department::factory()->create(['name' => 'Ventas']);

    $this->actingAs($admin)
        ->post(route('departments.store'), ['name' => 'Ventas', 'color' => 'neon'])
        ->assertSessionHasErrors([
            'name' => 'El valor de nombre ya está en uso.',
            'color' => 'El color no es válido. Elige un color de la paleta o un color personalizado con el formato #rrggbb.',
        ]);
});

test('supervisors cannot create or edit departments', function () {
    $department = Department::factory()->create();
    $supervisor = User::factory()->supervisor()->inDepartments($department)->create();

    $this->actingAs($supervisor)
        ->post(route('departments.store'), ['name' => 'Nuevo', 'color' => 'blue'])
        ->assertForbidden();

    $this->actingAs($supervisor)
        ->put(route('departments.update', $department), ['name' => 'Renombrado', 'color' => 'blue'])
        ->assertForbidden();

    $this->actingAs($supervisor)
        ->delete(route('departments.destroy', $department))
        ->assertForbidden();
});

test('administrators can update a department and the change is audited', function () {
    $admin = User::factory()->superAdministrator()->create();
    $department = Department::factory()->create(['name' => 'Ventas', 'color' => 'blue']);

    $this->actingAs($admin)
        ->put(route('departments.update', $department), ['name' => 'Ventas Mayoreo', 'color' => 'rose'])
        ->assertSessionHasNoErrors();

    expect($department->refresh())
        ->name->toBe('Ventas Mayoreo')
        ->slug->toBe('ventas-mayoreo');

    expect(AuditLog::query()->where('action', AuditAction::DepartmentUpdated)->sole()->properties['changes'])
        ->toMatchArray([
            'name' => ['old' => 'Ventas', 'new' => 'Ventas Mayoreo'],
            'color' => ['old' => 'blue', 'new' => 'rose'],
        ]);
});

test('administrators can delete and restore a department, which keeps its members and history', function () {
    $admin = User::factory()->superAdministrator()->create();
    $department = Department::factory()->create();
    $member = User::factory()->agent()->inDepartments($department)->create();
    $closed = Conversation::factory()->inDepartment($department)->closed()->create();

    $this->actingAs($admin)->delete(route('departments.destroy', $department))->assertSessionHasNoErrors();

    $this->assertModelExists($department);
    expect($department->refresh()->isDeleted())->toBeTrue()
        ->and($member->refresh()->belongsToDepartment($department))->toBeTrue()
        ->and($closed->refresh()->department_id)->toBe($department->id)
        ->and(AuditLog::query()->where('action', AuditAction::DepartmentDeleted)->sole()->properties)->toBe(['members_kept' => 1]);

    $this->actingAs($admin)->put(route('departments.update', $department), ['name' => 'Otro', 'color' => 'blue'])->assertForbidden();

    $this->actingAs($admin)->patch(route('departments.restore', $department))->assertSessionHasNoErrors();

    expect($department->refresh()->is_active)->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::DepartmentRestored)->count())->toBe(1);
});

test('a department with unclosed conversations cannot be deleted', function () {
    $admin = User::factory()->superAdministrator()->create();
    $department = Department::factory()->create();
    Conversation::factory()->inDepartment($department)->create();

    $this->actingAs($admin)
        ->delete(route('departments.destroy', $department))
        ->assertSessionHasErrors('department');

    expect($department->refresh()->is_active)->toBeTrue();
});

test('the default department of the platform cannot be deleted', function () {
    $admin = User::factory()->superAdministrator()->create();
    $department = Department::factory()->create();
    app(PlatformSettings::class)->update(['operations' => ['default_department_id' => $department->id]]);

    $this->actingAs($admin)
        ->delete(route('departments.destroy', $department))
        ->assertSessionHasErrors('department');

    expect($department->refresh()->is_active)->toBeTrue();
});

test('the default department of a connection cannot be deleted unless that connection is deleted', function () {
    $admin = User::factory()->superAdministrator()->create();
    $department = Department::factory()->create();
    $connection = Connection::factory()->create(['name' => 'Ventas WhatsApp', 'default_department_id' => $department->id]);

    $this->actingAs($admin)
        ->delete(route('departments.destroy', $department))
        ->assertSessionHasErrors(['department' => 'Es el departamento predeterminado de la conexión Ventas WhatsApp. Elige otro departamento predeterminado en la conexión antes de eliminarlo.']);

    expect($department->refresh()->is_active)->toBeTrue();

    $connection->forceFill(['deleted_at' => now()])->save();

    $this->actingAs($admin)->delete(route('departments.destroy', $department))->assertSessionHasNoErrors();

    expect($department->refresh()->isDeleted())->toBeTrue();
});

test('without records.view-deleted a deleted department is hidden from lists and answers as nonexistent', function () {
    $role = Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewDepartments, Permission::DeleteDepartments])->create();
    $manager = User::factory()->withRole($role)->create();
    $deleted = Department::factory()->inactive()->create();

    $this->actingAs($manager)
        ->get(route('departments.index', ['record' => 'deleted']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('departments', 0)
            ->where('recordStates', []));

    $this->actingAs($manager)->get(route('departments.show', $deleted))->assertNotFound();
    $this->actingAs($manager)->patch(route('departments.restore', $deleted))->assertForbidden();

    expect($deleted->refresh()->is_active)->toBeFalse();
});

test('each listed department exposes only the operations the viewer may perform', function () {
    $department = Department::factory()->create();
    $supervisor = User::factory()->supervisor()->inDepartments($department)->create();

    $this->actingAs($supervisor)
        ->get(route('departments.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('departments.0.can', ['view' => true, 'update' => false, 'delete' => false, 'restore' => false]));

    $this->actingAs($supervisor)->put(route('departments.update', $department), ['name' => 'Otro', 'color' => 'blue'])->assertForbidden();
    $this->actingAs($supervisor)->delete(route('departments.destroy', $department))->assertForbidden();

    $this->actingAs(User::factory()->superAdministrator()->create())
        ->get(route('departments.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('departments.0.can', ['view' => true, 'update' => true, 'delete' => true, 'restore' => false]));
});

test('saving a department without effective changes writes nothing and says so', function () {
    $admin = User::factory()->superAdministrator()->create();
    $department = Department::factory()->create(['name' => 'Ventas', 'description' => null, 'color' => 'blue']);
    $updatedAt = $department->updated_at;
    $this->travel(1)->hours();

    $writes = databaseWritesDuring(fn () => $this->actingAs($admin)
        ->put(route('departments.update', $department), ['name' => ' Ventas ', 'description' => '', 'color' => 'blue'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'No había cambios por guardar.']));

    expect($writes)->toBe([])
        ->and($department->refresh()->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::DepartmentUpdated)->exists())->toBeFalse();
});

test('a real change to a department is confirmed with a success toast', function () {
    $admin = User::factory()->superAdministrator()->create();
    $department = Department::factory()->create(['name' => 'Ventas', 'color' => 'blue']);

    $this->actingAs($admin)
        ->put(route('departments.update', $department), ['name' => 'Ventas', 'color' => 'rose'])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Departamento actualizado.']);

    expect(AuditLog::query()->where('action', AuditAction::DepartmentUpdated)->sole()->properties['changes'])->toHaveKey('color');
});
