<?php

use App\Models\Department;
use App\Services\Analytics\BusinessHoursCalendar;
use App\Services\PlatformSettings;
use Carbon\CarbonImmutable;

/*
| Deadlines in business time: addBusinessSeconds() is the exact inverse of
| businessSecondsBetween() (platform time zone, holidays, daylight saving).
*/

/**
 * A moment in Mexico City local time.
 */
function deadlineAt(string $localTime): CarbonImmutable
{
    return CarbonImmutable::parse($localTime, 'America/Mexico_City')->utc();
}

beforeEach(function () {
    // Default platform schedule: Monday to Friday 09:00-18:00.
    $this->settings = app(PlatformSettings::class);
    $this->settings->update(['business_hours' => ['enabled' => true]]);
    $this->calendar = app(BusinessHoursCalendar::class);
});

test('the deadline is the earliest instant with that much business time elapsed', function (string $from, int $seconds, string $deadline) {
    $start = deadlineAt($from);

    $due = $this->calendar->addBusinessSeconds($start, $seconds, null);

    expect($due->equalTo(deadlineAt($deadline)))->toBeTrue("Expected {$deadline}, got {$due->setTimezone('America/Mexico_City')}")
        // Inverse of businessSecondsBetween, and no earlier instant reaches it.
        ->and($this->calendar->businessSecondsBetween($start, $due, null))->toBe($seconds)
        ->and($this->calendar->businessSecondsBetween($start, $due->subSecond(), null))->toBe($seconds - 1);
})->with([
    'inside the schedule' => ['2026-10-19 10:00', 1800, '2026-10-19 10:30'],
    'starting at night counts from the next opening' => ['2026-10-19 20:00', 3600, '2026-10-20 10:00'],
    'starting before the opening' => ['2026-10-19 07:15', 60, '2026-10-19 09:01'],
    'across a night' => ['2026-10-19 17:30', 3600, '2026-10-20 09:30'],
    'across a weekend' => ['2026-10-23 17:00', 7200, '2026-10-26 10:00'],
    'starting on a weekend' => ['2026-10-24 12:00', 1, '2026-10-26 09:00:01'],
    'ending exactly at the closing time' => ['2026-10-23 17:00', 3600, '2026-10-23 18:00'],
    'several whole days' => ['2026-10-19 09:00', 3 * 9 * 3600, '2026-10-21 18:00'],
]);

test('a non-working day is skipped', function () {
    $this->settings->update(['business_hours' => ['holidays' => [['date' => '2025-10-26', 'label' => 'Recurrente', 'recurring' => true]]]]);
    $start = deadlineAt('2026-10-23 17:00');

    // Monday 2026-10-26 is a (recurring) non-working day.
    $due = $this->calendar->addBusinessSeconds($start, 7200, null);

    expect($due->equalTo(deadlineAt('2026-10-27 10:00')))->toBeTrue()
        ->and($this->calendar->businessSecondsBetween($start, $due, null))->toBe(7200);
});

test('daylight saving changes keep the local schedule', function () {
    $this->settings->update(['general' => ['timezone' => 'America/New_York']]);
    $department = Department::factory()->create([
        'uses_custom_hours' => true,
        'business_hours' => ['days' => array_fill_keys(PlatformSettings::WEEKDAYS, ['open' => true, 'start' => '09:00', 'end' => '10:00'])],
    ]);
    $newYork = fn (string $time): CarbonImmutable => CarbonImmutable::parse($time, 'America/New_York');

    // Daylight saving ends on Sunday 2026-11-01 (a 25-hour day) and starts
    // on Sunday 2026-03-08 (a 23-hour day): 30 + 60 + 30 minutes either way.
    foreach ([['2026-10-31 09:30', '2026-11-02 09:30'], ['2026-03-07 09:30', '2026-03-09 09:30']] as [$from, $expected]) {
        $due = $this->calendar->addBusinessSeconds($newYork($from), 7200, $department);

        expect($due->equalTo($newYork($expected)))->toBeTrue("Expected {$expected}, got {$due->setTimezone('America/New_York')}")
            ->and($this->calendar->businessSecondsBetween($newYork($from), $due, $department))->toBe(7200);
    }
});

test('with continuous attention the deadline is a plain addition', function () {
    $this->settings->update(['business_hours' => ['enabled' => false]]);
    $start = deadlineAt('2026-10-24 20:00');

    expect($this->calendar->addBusinessSeconds($start, 12 * 3600, null)->equalTo(deadlineAt('2026-10-25 08:00')))->toBeTrue()
        ->and($this->calendar->addBusinessSeconds($start, 0, null)->equalTo($start))->toBeTrue();
});

test('a schedule that never opens degrades to calendar time instead of looping', function () {
    $department = Department::factory()->create([
        'uses_custom_hours' => true,
        'business_hours' => ['days' => array_fill_keys(PlatformSettings::WEEKDAYS, ['open' => false, 'start' => '09:00', 'end' => '18:00'])],
    ]);
    $start = deadlineAt('2026-10-19 10:00');

    expect($this->calendar->addBusinessSeconds($start, 3600, $department)->equalTo(deadlineAt('2026-10-19 11:00')))->toBeTrue();
});

test('a deadline beyond the measurable span degrades to calendar time', function () {
    // One open minute per week: 2000 business minutes are far beyond MAX_DAYS.
    $days = array_fill_keys(PlatformSettings::WEEKDAYS, ['open' => false, 'start' => '09:00', 'end' => '18:00']);
    $days['monday'] = ['open' => true, 'start' => '09:00', 'end' => '09:01'];
    $department = Department::factory()->create(['uses_custom_hours' => true, 'business_hours' => ['days' => $days]]);
    $start = deadlineAt('2026-10-19 10:00');

    expect($this->calendar->addBusinessSeconds($start, 2000 * 60, $department)->equalTo($start->addSeconds(2000 * 60)))->toBeTrue();
});
