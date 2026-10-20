<?php

use App\Enums\AuditAction;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\QuickReply;
use App\Models\Role;
use App\Models\User;
use App\Services\PlatformSettings;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A department-scoped role allowed to edit departments, so scope (not the
 * missing permission) is what decides access.
 */
function departmentEditor(Department $department): User
{
    $role = Role::factory()
        ->scope(DataScope::Departments)
        ->withPermissions([Permission::ViewDepartments, Permission::UpdateDepartments])
        ->create();

    return User::factory()->withRole($role)->inDepartments($department)->create();
}

/**
 * @return array{monday: array{open: bool, start: string, end: string}, tuesday: array{open: bool, start: string, end: string}, wednesday: array{open: bool, start: string, end: string}, thursday: array{open: bool, start: string, end: string}, friday: array{open: bool, start: string, end: string}, saturday: array{open: bool, start: string, end: string}, sunday: array{open: bool, start: string, end: string}}
 */
function customWeek(): array
{
    $days = [];

    foreach (PlatformSettings::WEEKDAYS as $day) {
        $days[$day] = ['open' => $day !== 'sunday', 'start' => '08:00', 'end' => '16:00'];
    }

    return $days;
}

test('administrators update the welcome message and the change is audited', function () {
    $admin = User::factory()->administrator()->create();
    $department = Department::factory()->create();

    $this->actingAs($admin)
        ->put(route('departments.operations.update', $department), ['welcome_message' => '  Hola, te atiende Soporte.  '])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($department->refresh()->welcome_message)->toBe('Hola, te atiende Soporte.');

    $log = AuditLog::query()->where('action', AuditAction::DepartmentOperationsUpdated)->sole();

    expect($log->auditable_id)->toBe($department->id)
        ->and($log->properties['changes'])->toBe([
            'welcome_message' => ['old' => null, 'new' => 'Hola, te atiende Soporte.'],
        ]);
});

test('an empty welcome message clears it and an unchanged one is not audited', function () {
    $admin = User::factory()->administrator()->create();
    $department = Department::factory()->create();
    $department->forceFill(['welcome_message' => 'Hola'])->save();

    $this->actingAs($admin)->put(route('departments.operations.update', $department), ['welcome_message' => 'Hola']);
    expect(AuditLog::query()->where('action', AuditAction::DepartmentOperationsUpdated)->exists())->toBeFalse();

    $this->actingAs($admin)->put(route('departments.operations.update', $department), ['welcome_message' => '']);
    expect($department->refresh()->welcome_message)->toBeNull();
});

test('the welcome message cannot exceed 1024 characters', function () {
    $admin = User::factory()->administrator()->create();
    $department = Department::factory()->create();

    $this->actingAs($admin)
        ->put(route('departments.operations.update', $department), ['welcome_message' => str_repeat('a', 1025)])
        ->assertSessionHasErrors('welcome_message');

    $this->actingAs($admin)
        ->put(route('departments.operations.update', $department), ['welcome_message' => str_repeat('a', 1024)])
        ->assertSessionHasNoErrors();
});

test('only users who can update the department in their scope change its operations and hours', function () {
    $department = Department::factory()->create();
    $other = Department::factory()->create();
    $editor = departmentEditor($department);
    $supervisor = User::factory()->supervisor()->inDepartments($department)->create();
    $agent = User::factory()->agent()->inDepartments($department)->create();
    $hours = ['uses_custom_hours' => true, 'business_hours' => ['days' => customWeek()]];

    $this->actingAs($editor)
        ->put(route('departments.operations.update', $department), ['welcome_message' => 'Hola'])
        ->assertSessionHasNoErrors();
    $this->actingAs($editor)
        ->put(route('departments.hours.update', $department), $hours)
        ->assertSessionHasNoErrors();

    $this->actingAs($editor)->put(route('departments.operations.update', $other), ['welcome_message' => 'Hola'])->assertForbidden();
    $this->actingAs($editor)->put(route('departments.hours.update', $other), $hours)->assertForbidden();

    foreach ([$supervisor, $agent] as $user) {
        $this->actingAs($user)->put(route('departments.operations.update', $department), ['welcome_message' => 'Otro'])->assertForbidden();
        $this->actingAs($user)->put(route('departments.hours.update', $department), $hours)->assertForbidden();
    }

    expect($department->refresh()->welcome_message)->toBe('Hola')
        ->and($other->refresh())
        ->welcome_message->toBeNull()
        ->uses_custom_hours->toBeFalse();
});

test('a department can switch to a custom schedule and the diff is audited', function () {
    $admin = User::factory()->administrator()->create();
    $department = Department::factory()->create();

    $this->actingAs($admin)
        ->putJson(route('departments.hours.update', $department), [
            'uses_custom_hours' => true,
            'business_hours' => ['days' => customWeek()],
        ])
        ->assertRedirect();

    expect($department->refresh())
        ->uses_custom_hours->toBeTrue()
        ->business_hours->toBe(['days' => customWeek()]);

    $week = customWeek();
    $week['monday']['end'] = '19:30';

    $this->actingAs($admin)
        ->put(route('departments.hours.update', $department), [
            'uses_custom_hours' => true,
            'business_hours' => ['days' => $week],
        ])
        ->assertSessionHasNoErrors();

    $logs = AuditLog::query()->where('action', AuditAction::DepartmentHoursUpdated)->orderBy('id')->get();

    expect($logs)->toHaveCount(2)
        ->and($logs[0]->properties['changes'])->toMatchArray([
            'uses_custom_hours' => ['old' => false, 'new' => true],
            'business_hours.days.monday.end' => ['old' => null, 'new' => '16:00'],
        ])
        ->and($logs[1]->properties['changes'])->toBe([
            'business_hours.days.monday.end' => ['old' => '16:00', 'new' => '19:30'],
        ]);
});

test('returning to platform hours keeps the stored custom schedule', function () {
    $admin = User::factory()->administrator()->create();
    $department = Department::factory()->create();
    $department->forceFill(['uses_custom_hours' => true, 'business_hours' => ['days' => customWeek()]])->save();

    $this->actingAs($admin)
        ->put(route('departments.hours.update', $department), ['uses_custom_hours' => false])
        ->assertSessionHasNoErrors();

    expect($department->refresh())
        ->uses_custom_hours->toBeFalse()
        ->business_hours->toBe(['days' => customWeek()]);

    expect(AuditLog::query()->where('action', AuditAction::DepartmentHoursUpdated)->sole()->properties['changes'])
        ->toBe(['uses_custom_hours' => ['old' => true, 'new' => false]]);
});

test('custom hours are validated like the platform hours', function () {
    $admin = User::factory()->administrator()->create();
    $department = Department::factory()->create();
    $week = customWeek();
    $week['monday']['start'] = '9am';
    $week['tuesday'] = ['open' => true, 'start' => '18:00', 'end' => '09:00'];
    $week['wednesday'] = ['open' => true, 'start' => '10:00', 'end' => '10:00'];
    $week['sunday'] = ['open' => false, 'start' => '18:00', 'end' => '09:00'];
    unset($week['saturday']);

    $this->actingAs($admin)
        ->put(route('departments.hours.update', $department), [
            'uses_custom_hours' => true,
            'business_hours' => ['days' => $week],
        ])
        ->assertSessionHasErrors([
            'business_hours.days.monday.start',
            'business_hours.days.tuesday.end' => 'La hora de cierre debe ser posterior a la hora de apertura.',
            'business_hours.days.wednesday.end',
            'business_hours.days.saturday',
        ])
        ->assertSessionDoesntHaveErrors('business_hours.days.sunday.end');

    $this->actingAs($admin)
        ->put(route('departments.hours.update', $department), ['uses_custom_hours' => true])
        ->assertSessionHasErrors('business_hours');

    $this->actingAs($admin)
        ->put(route('departments.hours.update', $department), [])
        ->assertSessionHasErrors('uses_custom_hours');

    expect($department->refresh()->uses_custom_hours)->toBeFalse();
});

test('operational metrics count only the conversations of the department', function () {
    $this->travelTo(now()->setDate(2026, 10, 17)->setTime(18, 0));

    $admin = User::factory()->administrator()->create();
    $department = Department::factory()->create();
    $other = Department::factory()->create();
    $agent = User::factory()->agent()->inDepartments($department)->create();
    User::factory()->agent()->inDepartments($department)->create();

    Conversation::factory()->count(2)->inDepartment($department)->create();
    Conversation::factory()->inDepartment($department)->assignedTo($agent)->create();
    Conversation::factory()->inDepartment($department)->closed()->create();
    Conversation::factory()->inDepartment($department)->closed()->create(['closed_at' => now()->subDays(3)]);
    Conversation::factory()->inDepartment($other)->create();
    Conversation::factory()->inDepartment($other)->closed()->create();
    Conversation::factory()->create();

    $this->actingAs($admin)
        ->get(route('departments.show', $department))
        ->assertInertia(fn (Assert $page) => $page
            ->component('departments/Show')
            ->where('metrics', [
                'total' => 5,
                'pending' => 2,
                'open' => 1,
                'unassigned' => 2,
                'closed_today' => 1,
                'closed_total' => 2,
            ])
            ->where('department.members_count', 2)
        );
});

test('a department without conversations reports zero totals', function () {
    $admin = User::factory()->administrator()->create();
    $department = Department::factory()->create();

    $this->actingAs($admin)
        ->get(route('departments.show', $department))
        ->assertInertia(fn (Assert $page) => $page->where('metrics.total', 0));
});

test('the detail lists only the connections and specific quick replies of the department', function () {
    $admin = User::factory()->administrator()->create();
    $department = Department::factory()->create();
    $other = Department::factory()->create();

    $connection = Connection::factory()->create(['name' => 'Ventas WhatsApp', 'default_department_id' => $department->id]);
    $connection->departments()->attach($department);
    Connection::factory()->create()->departments()->attach($other);

    $specific = QuickReply::factory()->forDepartments($department)->create(['title' => 'Horario']);
    $inactive = QuickReply::factory()->forDepartments($department, $other)->inactive()->create(['title' => 'Antiguo']);
    QuickReply::factory()->forDepartments($other)->create();
    QuickReply::factory()->count(2)->create();
    QuickReply::factory()->inactive()->create();

    $this->actingAs($admin)
        ->get(route('departments.show', $department))
        ->assertInertia(fn (Assert $page) => $page
            ->where('department.connections_count', 1)
            ->has('connections', 1)
            ->where('connections.0.id', $connection->id)
            ->where('connections.0.is_default', true)
            ->where('connections.0.url', Route::has('connections.show') ? route('connections.show', $connection) : null)
            ->missing('connections.0.access_token')
            ->where('quickReplies.items', [
                ['id' => $specific->id, 'title' => 'Horario', 'shortcut' => $specific->shortcut, 'is_active' => true],
                ['id' => $inactive->id, 'title' => 'Antiguo', 'shortcut' => $inactive->shortcut, 'is_active' => false],
            ])
            ->where('quickReplies.global_count', 2)
        );
});

test('connection links and quick replies follow the viewer permissions', function () {
    $department = Department::factory()->create();
    Connection::factory()->create()->departments()->attach($department);
    $supervisor = User::factory()->supervisor()->inDepartments($department)->create();
    $viewer = User::factory()
        ->withRole(Role::factory()->scope(DataScope::Departments)->withPermissions([Permission::ViewDepartments])->create())
        ->inDepartments($department)
        ->create();

    $this->actingAs($supervisor)
        ->get(route('departments.show', $department))
        ->assertInertia(fn (Assert $page) => $page
            ->where('connections.0.url', null)
            ->where('links.connections', null)
            ->has('quickReplies.items')
            ->where('can.update', false)
        );

    $this->actingAs($viewer)
        ->get(route('departments.show', $department))
        ->assertInertia(fn (Assert $page) => $page->where('quickReplies', null));
});

test('the department index includes the number of associated connections', function () {
    $admin = User::factory()->administrator()->create();
    $department = Department::factory()->create();
    Connection::factory()->count(2)->create()->each(fn (Connection $connection) => $connection->departments()->attach($department));

    $this->actingAs($admin)
        ->get(route('departments.index'))
        ->assertInertia(fn (Assert $page) => $page->where('departments.0.connections_count', 2));
});
