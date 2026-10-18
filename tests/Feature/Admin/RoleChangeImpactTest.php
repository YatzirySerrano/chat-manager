<?php

use App\Actions\Users\AssessRoleChange;
use App\Actions\Users\UpdateUser;
use App\Enums\AuditAction;
use App\Enums\ConversationStatus;
use App\Enums\DataScope;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * @return array<string, mixed>
 */
function previewRoleChange(User $actor, User $user, DefaultRole $to): array
{
    return test()->actingAs($actor)
        ->postJson(route('admin.users.role-impact', $user), ['role_id' => role($to)->id])
        ->assertOk()
        ->json();
}

test('the preview explains what a supervisor loses when becoming an agent', function () {
    $admin = User::factory()->administrator()->create();
    $sales = Department::factory()->create(['name' => 'Ventas']);
    $supervisor = User::factory()->supervisor()->inDepartments($sales)->create();

    $impact = previewRoleChange($admin, $supervisor, DefaultRole::Agent);

    expect($impact)
        ->from->label->toBe('Supervisor')
        ->to->label->toBe('Agente')
        ->significant->toBeTrue()
        ->scope_changed->toBeTrue()
        ->blockers->toBe([])
        ->departments_to_leave->toBe([])
        ->and(collect($impact['current_departments'])->pluck('name')->all())->toBe(['Ventas'])
        ->and(collect($impact['lost_permissions'])->pluck('label')->all())->toContain('Administrar miembros', 'Ver departamentos');

    expect($supervisor->refresh()->hasRole('supervisor'))->toBeTrue();
});

test('a significant role change requires confirming the impact that was previewed', function () {
    $admin = User::factory()->administrator()->create();
    $supervisor = User::factory()->supervisor()->create();
    $payload = ['name' => $supervisor->name, 'email' => $supervisor->email, 'role_id' => role(DefaultRole::Agent)->id];

    $this->actingAs($admin)
        ->put(route('admin.users.update', $supervisor), $payload)
        ->assertSessionHasErrors('role_change');

    expect($supervisor->refresh()->hasRole('supervisor'))->toBeTrue();

    $impact = previewRoleChange($admin, $supervisor, DefaultRole::Agent);

    $this->actingAs($admin)
        ->put(route('admin.users.update', $supervisor), [...$payload, 'role_change_fingerprint' => $impact['fingerprint']])
        ->assertSessionHasNoErrors();

    expect($supervisor->refresh()->hasRole('agent'))->toBeTrue();

    expect(AuditLog::query()->where('action', AuditAction::UserRoleChanged)->sole()->properties)
        ->toMatchArray(['from' => 'Supervisor', 'to' => 'Agente', 'scope' => 'Solo lo asignado'])
        ->and(AuditLog::query()->where('action', AuditAction::UserRoleChanged)->sole()->properties['lost_permissions'])
        ->toContain('departments.manage-members');
});

test('a confirmation based on stale information is rejected', function () {
    $admin = User::factory()->administrator()->create();
    $department = Department::factory()->create();
    $supervisor = User::factory()->supervisor()->inDepartments($department)->create();

    $impact = previewRoleChange($admin, $supervisor, DefaultRole::Administrator);

    // Meanwhile the user received an open conversation and joined another department.
    $supervisor->departments()->attach(Department::factory()->create());

    $this->actingAs($admin)
        ->put(route('admin.users.update', $supervisor), [
            'name' => $supervisor->name,
            'email' => $supervisor->email,
            'role_id' => role(DefaultRole::Administrator)->id,
            'role_change_fingerprint' => $impact['fingerprint'],
        ])
        ->assertSessionHasErrors('role_change');

    expect($supervisor->refresh()->hasRole('supervisor'))->toBeTrue();
});

test('moving a user to an organization-wide role leaves their departments and is explained first', function () {
    $admin = User::factory()->administrator()->create();
    $sales = Department::factory()->create(['name' => 'Ventas']);
    $agent = User::factory()->agent()->inDepartments($sales)->create();

    $impact = previewRoleChange($admin, $agent, DefaultRole::Administrator);

    expect(collect($impact['departments_to_leave'])->pluck('name')->all())->toBe(['Ventas']);

    $this->actingAs($admin)->put(route('admin.users.update', $agent), [
        'name' => $agent->name,
        'email' => $agent->email,
        'role_id' => role(DefaultRole::Administrator)->id,
        'department_ids' => [$sales->id],
        'role_change_fingerprint' => $impact['fingerprint'],
    ])->assertSessionHasNoErrors();

    expect($agent->refresh()->hasRootAuthority())->toBeTrue()
        ->and($agent->departmentIds())->toBe([]);
});

test('open conversations return to the queue when the new role cannot attend them', function () {
    $admin = User::factory()->administrator()->create();
    $department = Department::factory()->create();
    $agent = User::factory()->agent()->inDepartments($department)->create();
    $conversation = Conversation::factory()->inDepartment($department)->assignedTo($agent)->create();
    $readOnly = Role::factory()->scope(DataScope::Departments)
        ->withPermissions([Permission::ViewDepartments])
        ->create(['label' => 'Consulta']);

    $impact = $this->actingAs($admin)
        ->postJson(route('admin.users.role-impact', $agent), ['role_id' => $readOnly->id])
        ->json();

    expect($impact['conversations_to_release'])->toBe(1);

    $this->actingAs($admin)->put(route('admin.users.update', $agent), [
        'name' => $agent->name,
        'email' => $agent->email,
        'role_id' => $readOnly->id,
        'role_change_fingerprint' => $impact['fingerprint'],
    ])->assertSessionHasNoErrors();

    expect($conversation->refresh())
        ->assigned_user_id->toBeNull()
        ->status->toBe(ConversationStatus::Pending);
});

test('a change that breaks a rule is blocked with an explanation', function () {
    $lastAdministrator = User::factory()->administrator()->create();
    $inactiveRole = Role::factory()->inactive()->create(['label' => 'Temporal']);

    $impact = app(AssessRoleChange::class)->handle($lastAdministrator, $inactiveRole, $lastAdministrator);

    expect($impact->blockers)->toBe([
        'No puedes modificar tu propio rol.',
        'El rol Temporal está desactivado. Actívalo antes de asignarlo.',
        'Es la última autoridad administrativa activa. Asigna el rol administrador a otra persona antes de cambiarle el rol.',
    ]);
});

test('the last root authority can never lose the administrator role', function () {
    $lastAdministrator = User::factory()->administrator()->create();
    $actor = User::factory()->administrator()->inactive()->create();

    expect(fn () => app(UpdateUser::class)->handle($lastAdministrator, [
        'name' => $lastAdministrator->name,
        'email' => $lastAdministrator->email,
        'role' => role(DefaultRole::Agent),
        'department_ids' => null,
    ], $actor))->toThrow(ValidationException::class);

    expect($lastAdministrator->refresh()->hasRootAuthority())->toBeTrue();
});

test('an administrator can be demoted while another root authority remains', function () {
    $admin = User::factory()->administrator()->create();
    $otherAdmin = User::factory()->administrator()->create();

    $impact = previewRoleChange($admin, $otherAdmin, DefaultRole::Supervisor);

    $this->actingAs($admin)
        ->put(route('admin.users.update', $otherAdmin), [
            'name' => $otherAdmin->name,
            'email' => $otherAdmin->email,
            'role_id' => role(DefaultRole::Supervisor)->id,
            'role_change_fingerprint' => $impact['fingerprint'],
        ])
        ->assertSessionHasNoErrors();

    expect($otherAdmin->refresh()->hasRole('supervisor'))->toBeTrue();
});

test('only users allowed to assign roles can preview a role change', function () {
    $supervisor = User::factory()->supervisor()->create();
    $agent = User::factory()->agent()->create();

    $this->actingAs($supervisor)
        ->postJson(route('admin.users.role-impact', $agent), ['role_id' => role(DefaultRole::Supervisor)->id])
        ->assertForbidden();
});
