<?php

use App\Actions\Analytics\RebuildConversationMetrics;
use App\Actions\Conversations\CloseConversation;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\ConversationMetric;
use App\Models\Department;
use App\Models\User;
use App\Services\Analytics\BusinessHoursCalendar;
use App\Services\PlatformSettings;
use Carbon\CarbonImmutable;

/*
| Business time of the reports: seconds inside the attention hours of the
| department that received the conversation (platform time zone, holidays,
| daylight saving), and the policy that freezes it once computed.
*/

/**
 * A moment in Mexico City local time.
 */
function mx(string $localTime): CarbonImmutable
{
    return CarbonImmutable::parse($localTime, 'America/Mexico_City')->utc();
}

beforeEach(function () {
    // Default platform schedule: Monday to Friday 09:00-18:00.
    $this->settings = app(PlatformSettings::class);
    $this->settings->update(['business_hours' => ['enabled' => true]]);
    $this->calendar = app(BusinessHoursCalendar::class);
});

test('only the seconds inside the schedule count, across nights, weekends and holidays', function () {
    // Same day, inside the schedule.
    expect($this->calendar->businessSecondsBetween(mx('2026-10-19 10:00'), mx('2026-10-19 10:30'), null))->toBe(1800)
        // Friday 17:00 → Monday 10:00: one hour on Friday and one on Monday.
        ->and($this->calendar->businessSecondsBetween(mx('2026-10-23 17:00'), mx('2026-10-26 10:00'), null))->toBe(7200)
        // Received at night, answered before opening: no business time elapsed.
        ->and($this->calendar->businessSecondsBetween(mx('2026-10-19 20:00'), mx('2026-10-20 08:30'), null))->toBe(0);

    $this->settings->update(['business_hours' => ['holidays' => [['date' => '2025-10-26', 'label' => 'Recurrente', 'recurring' => true]]]]);

    // Monday 2026-10-26 is now a (recurring) non-working day: only Friday's hour.
    expect($this->calendar->businessSecondsBetween(mx('2026-10-23 17:00'), mx('2026-10-26 10:00'), null))->toBe(3600);
});

test('with continuous attention business time equals calendar time', function () {
    $this->settings->update(['business_hours' => ['enabled' => false]]);

    expect($this->calendar->businessSecondsBetween(mx('2026-10-24 20:00'), mx('2026-10-25 08:00'), null))->toBe(12 * 3600);
});

test('the department schedule, the local time zone and daylight saving changes are honored', function () {
    $this->settings->update(['general' => ['timezone' => 'America/New_York']]);
    $department = Department::factory()->create([
        'uses_custom_hours' => true,
        'business_hours' => ['days' => array_fill_keys(PlatformSettings::WEEKDAYS, ['open' => true, 'start' => '09:00', 'end' => '10:00'])],
    ]);
    $newYork = fn (string $time): CarbonImmutable => CarbonImmutable::parse($time, 'America/New_York');

    // Daylight saving ends on Sunday 2026-11-01 (the day has 25 hours): the
    // opening stays at 09:00 local, so 30 + 60 + 30 minutes.
    expect($this->calendar->businessSecondsBetween($newYork('2026-10-31 09:30'), $newYork('2026-11-02 09:30'), $department))->toBe(2 * 3600);
});

test('the last day counts when daylight saving starts at midnight', function () {
    // In Santiago daylight saving starts on Sunday 2026-09-06 at 00:00 (that
    // midnight does not exist): Friday 17:00 → Monday 10:00 is one hour on
    // Friday and one on Monday, as on any other weekend.
    $this->settings->update(['general' => ['timezone' => 'America/Santiago']]);
    $santiago = fn (string $time): CarbonImmutable => CarbonImmutable::parse($time, 'America/Santiago');

    expect($this->calendar->businessSecondsBetween($santiago('2026-09-04 17:00'), $santiago('2026-09-07 10:00'), null))->toBe(7200)
        ->and($this->calendar->businessSecondsBetween($santiago('2026-09-05 08:00'), $santiago('2026-09-07 10:00'), null))->toBe(3600)
        ->and($this->calendar->businessSecondsBetween($santiago('2026-09-11 17:00'), $santiago('2026-09-14 10:00'), null))->toBe(7200);
});

test('a stored day that closes before it opens counts as closed instead of failing', function () {
    $department = Department::factory()->create([
        'uses_custom_hours' => true,
        'business_hours' => ['days' => array_fill_keys(PlatformSettings::WEEKDAYS, ['open' => true, 'start' => '22:00', 'end' => '06:00'])],
    ]);

    expect($this->calendar->businessSecondsBetween(mx('2026-10-19 21:00'), mx('2026-10-20 07:00'), $department))->toBe(0)
        ->and($this->calendar->isOutsideHours(mx('2026-10-19 23:00'), $department))->toBeTrue();
});

test('business time is frozen once computed and only an explicit rebuild reinterprets it', function () {
    $connection = Connection::factory()->operational()->create();
    $department = Department::factory()->create();
    $admin = User::factory()->administrator()->create();

    // Received Friday 17:30, closed Monday 09:30: 30 + 30 business minutes.
    $this->travelTo(mx('2026-10-23 17:30'));
    $conversation = Conversation::factory()->for($connection, 'channelConnection')->inDepartment($department)->create();
    $this->travelTo(mx('2026-10-26 09:30'));
    app(CloseConversation::class)->handle($conversation, $admin);
    $metric = fn (): ConversationMetric => ConversationMetric::query()->where('conversation_id', $conversation->id)->sole();

    expect($metric())
        ->seconds_to_resolution->toBe((2 * 24 + 16) * 3600)
        ->business_seconds_to_resolution->toBe(3600)
        ->business_seconds_to_first_human_response->toBeNull();

    // Editing the schedule later does not rewrite history…
    $this->settings->update(['business_hours' => ['enabled' => false]]);
    $this->artisan('analytics:rebuild')->assertSuccessful();

    expect($metric()->business_seconds_to_resolution)->toBe(3600);

    // …unless the rebuild is explicitly asked to recompute business time.
    $this->artisan('analytics:rebuild', ['--business-time' => true])->assertSuccessful();

    expect($metric()->business_seconds_to_resolution)->toBe((2 * 24 + 16) * 3600);
});

test('a rebuilt row without business time gets it computed once', function () {
    $connection = Connection::factory()->operational()->create();
    $this->travelTo(mx('2026-10-19 10:00'));
    $conversation = Conversation::factory()->for($connection, 'channelConnection')->create();
    $this->travelTo(mx('2026-10-19 10:20'));
    app(CloseConversation::class)->handle($conversation, User::factory()->administrator()->create());
    // A row computed before the business time columns existed.
    ConversationMetric::query()->update(['business_seconds_to_resolution' => null]);

    app(RebuildConversationMetrics::class)->handle();

    expect(ConversationMetric::query()->sole()->business_seconds_to_resolution)->toBe(1200);
});
