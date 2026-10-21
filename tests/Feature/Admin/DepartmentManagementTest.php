<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Department;
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
    $admin = User::factory()->administrator()->create();
    Department::factory()->count(2)->create();
    Department::factory()->inactive()->create();

    $this->actingAs($admin)
        ->get(route('departments.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('departments/Index')
            ->has('departments', 3)
            ->where('can.create', true)
        );
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
    $this->actingAs($supervisor)->get(route('departments.show', $other))->assertForbidden();
});

test('administrators can create a department', function () {
    $admin = User::factory()->administrator()->create();

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
    $admin = User::factory()->administrator()->create();
    Department::factory()->create(['name' => 'Ventas']);

    $this->actingAs($admin)
        ->post(route('departments.store'), ['name' => 'Ventas', 'color' => 'neon'])
        ->assertSessionHasErrors([
            'name' => 'El valor de nombre ya está en uso.',
            'color' => 'El valor seleccionado en color no es válido.',
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
        ->patch(route('departments.status.update', $department), ['is_active' => false])
        ->assertForbidden();
});

test('administrators can update a department and the change is audited', function () {
    $admin = User::factory()->administrator()->create();
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

test('administrators can deactivate and reactivate a department', function () {
    $admin = User::factory()->administrator()->create();
    $department = Department::factory()->create();

    $this->actingAs($admin)->patch(route('departments.status.update', $department), ['is_active' => false]);
    expect($department->refresh()->is_active)->toBeFalse();

    $this->actingAs($admin)->patch(route('departments.status.update', $department), ['is_active' => true]);
    expect($department->refresh()->is_active)->toBeTrue();

    expect(AuditLog::query()->whereIn('action', [AuditAction::DepartmentDeactivated, AuditAction::DepartmentActivated])->count())->toBe(2);
});

test('a department with unclosed conversations cannot be deactivated', function () {
    $admin = User::factory()->administrator()->create();
    $department = Department::factory()->create();
    Conversation::factory()->inDepartment($department)->create();

    $this->actingAs($admin)
        ->patch(route('departments.status.update', $department), ['is_active' => false])
        ->assertSessionHasErrors('department');

    expect($department->refresh()->is_active)->toBeTrue();
});

test('the default department cannot be deactivated', function () {
    $admin = User::factory()->administrator()->create();
    $department = Department::factory()->create();
    app(PlatformSettings::class)->update(['operations' => ['default_department_id' => $department->id]]);

    $this->actingAs($admin)
        ->patch(route('departments.status.update', $department), ['is_active' => false])
        ->assertSessionHasErrors('department');

    expect($department->refresh()->is_active)->toBeTrue();
});

test('each listed department exposes only the operations the viewer may perform', function () {
    $department = Department::factory()->create();
    $supervisor = User::factory()->supervisor()->inDepartments($department)->create();

    $this->actingAs($supervisor)
        ->get(route('departments.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('departments.0.can', ['view' => true, 'update' => false, 'changeStatus' => false]));

    $this->actingAs($supervisor)->put(route('departments.update', $department), ['name' => 'Otro', 'color' => 'blue'])->assertForbidden();
    $this->actingAs($supervisor)->patch(route('departments.status.update', $department), ['is_active' => false])->assertForbidden();

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('departments.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('departments.0.can', ['view' => true, 'update' => true, 'changeStatus' => true]));
});
