<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\User;
use App\Services\PlatformSettings;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Configuration screens of the automation module: non-working days of the
| platform and the out-of-hours message of a department.
*/

test('administrators save non-working days, sorted and audited', function () {
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)->put(route('admin.settings.holidays.update'), ['holidays' => [
        ['date' => '2026-12-25', 'label' => ' Navidad ', 'recurring' => true],
        ['date' => '2026-11-02', 'label' => 'Día de Muertos', 'recurring' => false],
    ]])->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Días no laborables guardados.']);

    expect(app(PlatformSettings::class)->get('business_hours.holidays'))->toBe([
        ['date' => '2026-11-02', 'label' => 'Día de Muertos', 'recurring' => false],
        ['date' => '2026-12-25', 'label' => 'Navidad', 'recurring' => true],
    ])
        ->and(app(PlatformSettings::class)->get('business_hours.days.monday.open'))->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::SettingsUpdated)->count())->toBe(1);
});

test('removing the last non-working day is saved', function () {
    $admin = User::factory()->administrator()->create();
    app(PlatformSettings::class)->update(['business_hours' => ['holidays' => [
        ['date' => '2026-11-02', 'label' => 'A', 'recurring' => false],
        ['date' => '2026-11-03', 'label' => 'B', 'recurring' => false],
    ]]]);

    $this->actingAs($admin)->put(route('admin.settings.holidays.update'), ['holidays' => [
        ['date' => '2026-11-02', 'label' => 'A', 'recurring' => false],
    ]])->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Días no laborables guardados.']);

    $this->actingAs($admin)->put(route('admin.settings.holidays.update'), ['holidays' => []]);

    expect(app(PlatformSettings::class)->get('business_hours.holidays'))->toBe([]);
});

test('non-working days are validated', function () {
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)->put(route('admin.settings.holidays.update'), ['holidays' => [
        ['date' => '2026-11-02', 'label' => 'A', 'recurring' => false],
        ['date' => '2026-11-02', 'label' => '', 'recurring' => false],
        ['date' => '02/11/2026', 'label' => 'C', 'recurring' => false],
    ]])->assertSessionHasErrors(['holidays.1.date', 'holidays.1.label', 'holidays.2.date']);
});

test('only users who can update settings change non-working days', function () {
    $supervisor = User::factory()->supervisor()->create();

    $this->actingAs($supervisor)->put(route('admin.settings.holidays.update'), ['holidays' => []])->assertForbidden();
});

test('guests cannot change non-working days', function () {
    $this->put(route('admin.settings.holidays.update'), ['holidays' => []])->assertRedirect(route('login'));
});

test('the settings page receives the non-working days', function () {
    app(PlatformSettings::class)->update(['business_hours' => ['holidays' => [['date' => '2026-11-02', 'label' => 'A', 'recurring' => false]]]]);

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('admin.settings.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('settings.business_hours.holidays.0.label', 'A'));
});

test('the department away message is saved independently of the welcome message', function () {
    $admin = User::factory()->superAdministrator()->create();
    $department = Department::factory()->create();
    $department->forceFill(['welcome_message' => 'Hola'])->save();

    $this->actingAs($admin)->put(route('departments.operations.update', $department), ['away_message' => '  Volvemos mañana.  '])
        ->assertSessionHasNoErrors();

    expect($department->refresh())
        ->welcome_message->toBe('Hola')
        ->away_message->toBe('Volvemos mañana.');

    $this->actingAs($admin)->put(route('departments.operations.update', $department), ['away_message' => str_repeat('a', 1025)])
        ->assertSessionHasErrors('away_message');
});

test('the department page shows whether it is attending now and the global load limit', function () {
    app(PlatformSettings::class)->update(['operations' => ['max_open_conversations_per_agent' => 5]]);
    $department = Department::factory()->create();

    $this->actingAs(User::factory()->superAdministrator()->create())
        ->get(route('departments.show', $department))
        ->assertInertia(fn (Assert $page) => $page
            ->where('automation.load_limit', 5)
            ->where('automation.hours.mode', 'continuous')
            ->where('automation.hours.is_open', true)
            ->where('department.away_message', null));
});
