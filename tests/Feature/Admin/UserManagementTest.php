<?php

use App\Actions\Users\CreateUser;
use App\Enums\AuditAction;
use App\Enums\DataScope;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $this->get(route('admin.users.index'))->assertRedirect(route('login'));
});

test('users without the permission cannot access user administration', function (DefaultRole $role) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
    $this->actingAs($user)->post(route('admin.users.store'), [])->assertForbidden();
})->with([
    'supervisor' => DefaultRole::Supervisor,
    'agent' => DefaultRole::Agent,
]);

test('administrators can list users with their roles and departments', function () {
    $admin = User::factory()->administrator()->create(['name' => 'Administrador']);
    $department = Department::factory()->create(['name' => 'Ventas']);
    User::factory()->agent()->inDepartments($department)->create(['name' => 'Ana Agente']);

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/users/Index')
            ->has('users.data', 2)
            ->where('users.data.0.name', 'Administrador')
            ->where('users.data.0.can.changeStatus', false)
            ->where('users.data.1.name', 'Ana Agente')
            ->where('users.data.1.role.label', 'Agente')
            ->where('users.data.1.departments.0.name', 'Ventas')
            ->where('users.data.1.can.changeStatus', true)
            ->where('stats.total', 2)
            ->where('can.create', true)
        );
});

test('the user list can be searched, filtered and sorted', function () {
    $admin = User::factory()->administrator()->create(['name' => 'Administrador']);
    $support = Department::factory()->create();
    User::factory()->agent()->inDepartments($support)->create(['name' => 'Laura Soporte']);
    User::factory()->agent()->create(['name' => 'Luis Ventas']);
    User::factory()->supervisor()->inDepartments($support)->create(['name' => 'Laura Supervisora']);
    User::factory()->agent()->inactive()->create(['name' => 'Laura Inactiva', 'created_at' => now()->addMinute()]);

    $this->actingAs($admin)
        ->get(route('admin.users.index', [
            'search' => 'laura',
            'role' => role(DefaultRole::Agent)->id,
            'department' => $support->id,
            'status' => 'active',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.name', 'Laura Soporte')
        );

    $this->actingAs($admin)
        ->get(route('admin.users.index', ['sort' => 'newest']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.sort', 'newest')
            ->where('users.data.0.name', 'Laura Inactiva')
        );
});

test('search terms are treated literally', function () {
    $admin = User::factory()->administrator()->create();
    User::factory()->agent()->create(['name' => 'Agente Uno']);

    $this->actingAs($admin)
        ->get(route('admin.users.index', ['search' => '%']))
        ->assertInertia(fn (Assert $page) => $page->has('users.data', 0));
});

test('administrators can create employees with a role and departments', function () {
    $admin = User::factory()->administrator()->create();
    $departments = Department::factory()->count(2)->create();

    $this->actingAs($admin)
        ->from(route('admin.users.index', ['status' => 'inactive']))
        ->post(route('admin.users.store'), [
            'name' => 'Carla Agente',
            'email' => 'CARLA@empresa.test',
            'password' => 'contraseña-segura',
            'password_confirmation' => 'contraseña-segura',
            'role_id' => role(DefaultRole::Agent)->id,
            'department_ids' => $departments->pluck('id')->all(),
            'is_active' => false,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.users.index', ['status' => 'inactive']))
        ->assertInertiaFlash('highlight.email', 'carla@empresa.test');

    $user = User::query()->where('email', 'carla@empresa.test')->firstOrFail();

    expect($user->hasRole('agent'))->toBeTrue()
        ->and($user->is_active)->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->departmentIds())->toEqualCanonicalizing($departments->pluck('id')->all());

    $log = AuditLog::query()->where('action', AuditAction::UserCreated)->sole();

    expect($log->user_id)->toBe($admin->id)
        ->and($log->auditable_id)->toBe($user->id)
        ->and($log->properties)->toHaveKey('role', 'Agente')
        ->and(json_encode($log->properties))->not->toContain('contraseña-segura');
});

test('users with an organization-wide role are never attached to departments', function () {
    $admin = User::factory()->administrator()->create();
    $department = Department::factory()->create();

    $this->actingAs($admin)->post(route('admin.users.store'), [
        'name' => 'Otro Admin',
        'email' => 'otro@empresa.test',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role_id' => role(DefaultRole::Administrator)->id,
        'department_ids' => [$department->id],
    ])->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'otro@empresa.test')->sole()->departments()->count())->toBe(0);
});

test('custom roles can be assigned to new users', function () {
    $admin = User::factory()->administrator()->create();
    $auditor = Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewAuditLog])->create(['label' => 'Auditor Operativo']);

    $this->actingAs($admin)->post(route('admin.users.store'), [
        'name' => 'Iris Auditora',
        'email' => 'iris@empresa.test',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role_id' => $auditor->id,
    ])->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'iris@empresa.test')->sole();

    expect($user->role()?->is($auditor))->toBeTrue()
        ->and($user->holdsPermission(Permission::ViewAuditLog))->toBeTrue()
        ->and($user->holdsPermission(Permission::ViewUsers))->toBeFalse();
});

test('inactive roles cannot be assigned', function () {
    $admin = User::factory()->administrator()->create();
    $inactive = Role::factory()->inactive()->create();

    $this->actingAs($admin)->post(route('admin.users.store'), [
        'name' => 'Sin Rol',
        'email' => 'sinrol@empresa.test',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role_id' => $inactive->id,
    ])->assertSessionHasErrors(['role_id' => 'El rol seleccionado está desactivado.']);
});

test('creating a user requires valid data', function () {
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)
        ->post(route('admin.users.store'), [])
        ->assertSessionHasErrors([
            'name' => 'El campo nombre es obligatorio.',
            'email' => 'El campo correo electrónico es obligatorio.',
            'password' => 'El campo contraseña es obligatorio.',
            'role_id' => 'El campo rol es obligatorio.',
        ]);
});

test('users cannot be created with a duplicated email or an inactive department', function () {
    $admin = User::factory()->administrator()->create(['email' => 'admin@empresa.test']);
    $inactive = Department::factory()->inactive()->create();

    $this->actingAs($admin)
        ->post(route('admin.users.store'), [
            'name' => 'Duplicado',
            'email' => 'admin@empresa.test',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role_id' => role(DefaultRole::Agent)->id,
            'department_ids' => [$inactive->id],
        ])
        ->assertSessionHasErrors(['email', 'department_ids.0']);
});

test('administrators can change the departments of a user', function () {
    $admin = User::factory()->administrator()->create();
    $sales = Department::factory()->create(['name' => 'Ventas']);
    $support = Department::factory()->create(['name' => 'Soporte']);
    $user = User::factory()->agent()->inDepartments($sales)->create();

    $this->actingAs($admin)
        ->put(route('admin.users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'role_id' => role(DefaultRole::Agent)->id,
            'department_ids' => [$support->id],
        ])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->departmentIds())->toBe([$support->id])
        ->and(AuditLog::query()->where('action', AuditAction::UserRoleChanged)->exists())->toBeFalse();

    expect(AuditLog::query()->where('action', AuditAction::UserDepartmentsChanged)->sole()->properties)
        ->toMatchArray(['added' => ['Soporte'], 'removed' => ['Ventas']]);
});

test('an administrator can reset a password without it being audited in plain text', function () {
    $admin = User::factory()->administrator()->create();
    $user = User::factory()->agent()->create();

    $this->actingAs($admin)->put(route('admin.users.update', $user), [
        'name' => $user->name,
        'email' => $user->email,
        'password' => 'nueva-contraseña-1',
        'password_confirmation' => 'nueva-contraseña-1',
    ])->assertSessionHasNoErrors();

    expect(Hash::check('nueva-contraseña-1', $user->refresh()->password))->toBeTrue();

    $log = AuditLog::query()->where('action', AuditAction::UserPasswordReset)->sole();
    expect(json_encode($log->properties))->not->toContain('nueva-contraseña-1');
});

test('nobody can change their own role', function () {
    $admin = User::factory()->administrator()->create();
    User::factory()->administrator()->create();

    $this->actingAs($admin)
        ->put(route('admin.users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'role_id' => role(DefaultRole::Agent)->id,
        ])
        ->assertForbidden();

    expect($admin->refresh()->hasRootAuthority())->toBeTrue();
});

test('users without operational history can be deleted keeping the current filters', function () {
    $admin = User::factory()->administrator()->create();
    $user = User::factory()->agent()->create();

    $this->actingAs($admin)
        ->from(route('admin.users.index', ['search' => 'agente']))
        ->delete(route('admin.users.destroy', $user))
        ->assertRedirect(route('admin.users.index', ['search' => 'agente']));

    $this->assertModelMissing($user);
    expect(AuditLog::query()->where('action', AuditAction::UserDeleted)->sole()->auditable_label)
        ->toContain($user->email);
});

test('users with operational history must be deactivated instead of deleted', function () {
    $admin = User::factory()->administrator()->create();
    $agent = User::factory()->agent()->create();
    Conversation::factory()->assignedTo($agent)->create();

    $this->actingAs($admin)
        ->delete(route('admin.users.destroy', $agent))
        ->assertSessionHasErrors('user');

    $this->assertModelExists($agent);
});

test('administrators cannot delete their own account from administration', function () {
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)->delete(route('admin.users.destroy', $admin))->assertForbidden();

    $this->assertModelExists($admin);
});

test('a duplicated email that slips past validation is reported as a validation error', function () {
    $admin = User::factory()->administrator()->create();
    User::factory()->create(['email' => 'repetido@empresa.test']);

    expect(fn () => app(CreateUser::class)->handle([
        'name' => 'Simultáneo',
        'email' => 'repetido@empresa.test',
        'password' => 'password',
        'role' => role(DefaultRole::Agent),
    ], $admin))->toThrow(ValidationException::class, 'El valor de correo electrónico ya está en uso.');

    expect(User::query()->where('email', 'repetido@empresa.test')->count())->toBe(1);
});
