<?php

use App\Enums\AuditAction;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Services\PlatformSettings;
use App\Services\ServiceRules;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A department-scoped role allowed to edit departments, member of the given one.
 */
function serviceRulesDepartmentEditor(Department $department): User
{
    $role = Role::factory()
        ->scope(DataScope::Departments)
        ->withPermissions([Permission::ViewDepartments, Permission::UpdateDepartments])
        ->create();

    return User::factory()->withRole($role)->inDepartments($department)->create();
}

beforeEach(function () {
    // Phase-two behavior (FEATURE_PHASE_TWO): docs/reglas-de-servicio.md#fase-dos-detrás-de-feature_phase_two.
    config(['features.phase_two' => true]);
});

test('a department saves its own service rules and the change is audited per rule', function () {
    $admin = User::factory()->superAdministrator()->create();
    $department = Department::factory()->create();

    $this->actingAs($admin)
        ->put(route('departments.service-rules.update', $department), ['rules' => [
            'sla' => ['first_response_minutes' => 15, 'warning_percent' => '70', 'business_hours_only' => false],
            'intake' => ['confirmation_required' => true],
            'queue' => ['min_wait_minutes' => 0, 'chronological_order' => true],
        ]])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($department->refresh()->service_rules)->toBe([
        'sla.first_response_minutes' => 15,
        'sla.warning_percent' => 70,
        'sla.business_hours_only' => false,
        'intake.confirmation_required' => true,
        'queue.min_wait_minutes' => 0,
        'queue.chronological_order' => true,
    ]);

    $log = AuditLog::query()->where('action', AuditAction::DepartmentServiceRulesUpdated)->sole();

    expect($log->auditable_id)->toBe($department->id)
        ->and($log->user_id)->toBe($admin->id)
        ->and($log->properties['changes'])->toBe([
            'sla.first_response_minutes' => ['old' => null, 'new' => 15],
            'sla.warning_percent' => ['old' => null, 'new' => 70],
            'sla.business_hours_only' => ['old' => null, 'new' => false],
            'intake.confirmation_required' => ['old' => null, 'new' => true],
            'queue.min_wait_minutes' => ['old' => null, 'new' => 0],
            'queue.chronological_order' => ['old' => null, 'new' => true],
        ]);

    $rules = app(ServiceRules::class)->resolve($department, null);

    expect($rules->firstResponseSeconds())->toBe(900)
        ->and($rules->businessHoursOnly())->toBeFalse()
        ->and($rules->chronologicalOrder())->toBeTrue();
});

test('a rule sent as null or left out goes back to inheriting, and only what changed is audited', function () {
    $admin = User::factory()->superAdministrator()->create();
    $department = Department::factory()->create(['service_rules' => [
        'sla.first_response_minutes' => 15,
        'sla.response_minutes' => 10,
        'queue.min_wait_minutes' => 5,
    ]]);

    $this->actingAs($admin)
        ->put(route('departments.service-rules.update', $department), ['rules' => [
            'sla' => ['first_response_minutes' => null],
            'queue' => ['min_wait_minutes' => 5],
        ]])
        ->assertSessionHasNoErrors();

    expect($department->refresh()->service_rules)->toBe(['queue.min_wait_minutes' => 5])
        ->and(AuditLog::query()->where('action', AuditAction::DepartmentServiceRulesUpdated)->sole()->properties['changes'])->toBe([
            'sla.first_response_minutes' => ['old' => 15, 'new' => null],
            'sla.response_minutes' => ['old' => 10, 'new' => null],
        ]);

    $this->actingAs($admin)
        ->put(route('departments.service-rules.update', $department), [])
        ->assertSessionHasNoErrors();

    expect($department->refresh()->service_rules)->toBeNull();
});

test('saving the same rules writes nothing', function () {
    $admin = User::factory()->superAdministrator()->create();
    $department = Department::factory()->create(['service_rules' => ['queue.min_wait_minutes' => 5]]);

    $this->actingAs($admin)
        ->put(route('departments.service-rules.update', $department), ['rules' => ['queue' => ['min_wait_minutes' => 5]]])
        ->assertSessionHasNoErrors();

    expect(AuditLog::query()->where('action', AuditAction::DepartmentServiceRulesUpdated)->exists())->toBeFalse();
});

test('department rules are validated', function (array $rules, string $error) {
    $admin = User::factory()->superAdministrator()->create();
    $department = Department::factory()->create();

    $this->actingAs($admin)
        ->put(route('departments.service-rules.update', $department), ['rules' => $rules])
        ->assertSessionHasErrors($error);

    expect($department->refresh()->service_rules)->toBeNull();
})->with([
    'objective below the minimum' => [['sla' => ['first_response_minutes' => 0]], 'rules.sla.first_response_minutes'],
    'warning out of range' => [['sla' => ['warning_percent' => 99]], 'rules.sla.warning_percent'],
    'wait out of range' => [['queue' => ['min_wait_minutes' => 2000]], 'rules.queue.min_wait_minutes'],
    'platform-only setting' => [['sla_alerts' => ['agent_warning' => false]], 'rules'],
    'unknown rule' => [['queue' => ['priority' => 1]], 'rules.queue'],
]);

test('only people who can edit the department change its service rules', function () {
    $department = Department::factory()->create();
    $other = Department::factory()->create();
    $editor = serviceRulesDepartmentEditor($department);
    $supervisor = User::factory()->supervisor()->inDepartments($department)->create();
    $agent = User::factory()->agent()->inDepartments($department)->create();
    $payload = ['rules' => ['queue' => ['min_wait_minutes' => 3]]];

    foreach ([$supervisor, $agent] as $user) {
        $this->actingAs($user)->put(route('departments.service-rules.update', $department), $payload)->assertForbidden();
    }

    $this->actingAs($editor)->put(route('departments.service-rules.update', $other), $payload)->assertNotFound();

    expect($department->refresh()->service_rules)->toBeNull()
        ->and($other->refresh()->service_rules)->toBeNull();

    $this->actingAs($editor)->put(route('departments.service-rules.update', $department), $payload)->assertSessionHasNoErrors();

    expect($department->refresh()->service_rules)->toBe(['queue.min_wait_minutes' => 3]);
});

test('a deleted department is read-only', function () {
    $admin = User::factory()->superAdministrator()->create();
    $department = Department::factory()->inactive()->create();

    $this->actingAs($admin)
        ->put(route('departments.service-rules.update', $department), ['rules' => ['queue' => ['min_wait_minutes' => 3]]])
        ->assertForbidden();
});

test('the department page describes its rules with what it inherits', function () {
    app(PlatformSettings::class)->update(['sla' => ['first_response_minutes' => 30]]);
    $admin = User::factory()->superAdministrator()->create();
    $department = Department::factory()->create(['service_rules' => ['queue.min_wait_minutes' => 4]]);

    $this->actingAs($admin)
        ->get(route('departments.show', $department))
        ->assertInertia(fn (Assert $page) => $page
            ->component('departments/Show')
            ->where('serviceRules', function ($rules): bool {
                $rules = collect($rules)->toArray();

                return $rules['sla.first_response_minutes']['value'] === 30
                    && $rules['sla.first_response_minutes']['source'] === 'platform'
                    && $rules['queue.min_wait_minutes']['value'] === 4
                    && $rules['queue.min_wait_minutes']['source'] === 'department'
                    && $rules['queue.min_wait_minutes']['inherited_value'] === 0
                    && $rules['queue.min_wait_minutes']['inherited_source'] === 'platform';
            })
        );
});
