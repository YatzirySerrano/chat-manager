<?php

use App\Models\Department;
use App\Services\BusinessHours;
use App\Services\PlatformSettings;
use Carbon\CarbonImmutable;

/*
| Business hours evaluated in the platform time zone: platform schedule,
| department schedule, continuous attention and non-working days.
*/

function weekSchedule(string $start = '09:00', string $end = '18:00', array $closed = ['saturday', 'sunday']): array
{
    $days = [];

    foreach (PlatformSettings::WEEKDAYS as $day) {
        $days[$day] = ['open' => ! in_array($day, $closed, true), 'start' => $start, 'end' => $end];
    }

    return $days;
}

function mexicoTime(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'America/Mexico_City');
}

beforeEach(function () {
    app(PlatformSettings::class)->update([
        'general' => ['timezone' => 'America/Mexico_City'],
        'business_hours' => ['enabled' => true, 'days' => weekSchedule()],
    ]);
});

test('the platform schedule is evaluated in the platform time zone', function () {
    $hours = app(BusinessHours::class);

    // Monday 2026-10-19.
    expect($hours->isOpenAt(null, mexicoTime('2026-10-19 09:00')))->toBeTrue()
        ->and($hours->isOpenAt(null, mexicoTime('2026-10-19 17:59')))->toBeTrue()
        ->and($hours->isOpenAt(null, mexicoTime('2026-10-19 18:00')))->toBeFalse()
        ->and($hours->isOpenAt(null, mexicoTime('2026-10-19 08:59')))->toBeFalse()
        // 16:00 UTC is 10:00 in Mexico City.
        ->and($hours->isOpenAt(null, CarbonImmutable::parse('2026-10-19 16:00', 'UTC')))->toBeTrue()
        ->and($hours->isOpenAt(null, mexicoTime('2026-10-24 11:00')))->toBeFalse();
});

test('without an applied schedule attention is continuous and holidays are ignored', function () {
    app(PlatformSettings::class)->update(['business_hours' => [
        'enabled' => false,
        'holidays' => [['date' => '2026-10-19', 'label' => 'Asueto', 'recurring' => false]],
    ]]);

    $hours = app(BusinessHours::class);

    expect($hours->mode(null))->toBe('continuous')
        ->and($hours->isOpenAt(null, mexicoTime('2026-10-19 03:00')))->toBeTrue()
        ->and($hours->nextOpening(null, mexicoTime('2026-10-19 03:00'))?->equalTo(mexicoTime('2026-10-19 03:00')))->toBeTrue();
});

test('a department with its own hours uses them instead of the platform schedule', function () {
    $department = Department::factory()->create();
    $department->forceFill(['uses_custom_hours' => true, 'business_hours' => ['days' => weekSchedule('07:00', '12:00', [])]])->save();
    $platformDepartment = Department::factory()->create();

    $hours = app(BusinessHours::class);
    $sundayMorning = mexicoTime('2026-10-25 08:00');

    expect($hours->mode($department))->toBe('department')
        ->and($hours->isOpenAt($department, $sundayMorning))->toBeTrue()
        ->and($hours->isOpenAt($platformDepartment, $sundayMorning))->toBeFalse()
        ->and($hours->isOpenAt($department, mexicoTime('2026-10-19 13:00')))->toBeFalse();
});

test('non-working days close every schedule, including recurring yearly ones', function () {
    $department = Department::factory()->create();
    $department->forceFill(['uses_custom_hours' => true, 'business_hours' => ['days' => weekSchedule()]])->save();

    app(PlatformSettings::class)->update(['business_hours' => ['holidays' => [
        ['date' => '2026-10-19', 'label' => 'Inventario', 'recurring' => false],
        ['date' => '2020-12-25', 'label' => 'Navidad', 'recurring' => true],
    ]]]);

    $hours = app(BusinessHours::class);

    expect($hours->isOpenAt(null, mexicoTime('2026-10-19 10:00')))->toBeFalse()
        ->and($hours->isOpenAt($department, mexicoTime('2026-10-19 10:00')))->toBeFalse()
        ->and($hours->isOpenAt(null, mexicoTime('2026-12-25 10:00')))->toBeFalse()
        ->and($hours->isOpenAt(null, mexicoTime('2026-10-20 10:00')))->toBeTrue()
        ->and($hours->describe(null, mexicoTime('2026-10-19 10:00')))
        ->holiday->toBe('Inventario')
        ->is_open->toBeFalse();
});

test('the next opening skips closed days and holidays', function () {
    app(PlatformSettings::class)->update(['business_hours' => ['holidays' => [
        ['date' => '2026-10-26', 'label' => 'Puente', 'recurring' => false],
    ]]]);

    $hours = app(BusinessHours::class);

    // Friday after closing → Monday is a holiday → Tuesday 09:00.
    $next = $hours->nextOpening(null, mexicoTime('2026-10-23 19:00'));

    expect($next?->setTimezone('America/Mexico_City')->format('Y-m-d H:i'))->toBe('2026-10-27 09:00')
        ->and($hours->nextOpening(null, mexicoTime('2026-10-20 07:00'))?->setTimezone('America/Mexico_City')->format('Y-m-d H:i'))->toBe('2026-10-20 09:00');
});

test('a schedule that never opens has no next opening', function () {
    app(PlatformSettings::class)->update(['business_hours' => ['days' => weekSchedule('09:00', '18:00', PlatformSettings::WEEKDAYS)]]);

    expect(app(BusinessHours::class)->nextOpening(null, mexicoTime('2026-10-19 10:00')))->toBeNull();
});
