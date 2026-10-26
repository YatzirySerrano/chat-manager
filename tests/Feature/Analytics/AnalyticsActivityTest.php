<?php

use App\Actions\Analytics\RebuildConversationMetrics;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\ConversationMetric;
use App\Models\Department;
use App\Models\User;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\AnalyticsScope;
use App\Services\Analytics\OperationalAnalytics;
use App\Services\PlatformSettings;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Activity buckets (received, attended, first response per hour/day/month):
| same cohort and definitions as the summary, local time of the platform.
*/

beforeEach(function () {
    // Wednesday 2026-10-21 12:00 local time (18:00 UTC).
    $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00', 'America/Mexico_City')->utc());
    $this->sales = Department::factory()->create(['name' => 'Ventas']);
    $this->support = Department::factory()->create(['name' => 'Soporte']);
    $this->connection = Connection::factory()->operational()->create();
    $this->ana = User::factory()->agent()->inDepartments($this->sales)->create(['name' => 'Ana Ventas']);
    $this->carla = User::factory()->agent()->inDepartments($this->support)->create(['name' => 'Carla Soporte']);
});

/**
 * A conversation received at the given UTC moment.
 */
function activityConversation(Department $department, ?User $agent, CarbonImmutable $receivedAt): Conversation
{
    $factory = Conversation::factory()->for(test()->connection, 'channelConnection')->inDepartment($department);
    $factory = $agent === null ? $factory : $factory->assignedTo($agent);

    return $factory->create(['created_at' => $receivedAt, 'updated_at' => $receivedAt]);
}

/**
 * Rebuild every metric, then override some columns per conversation (a
 * rebuild recomputes every row).
 *
 * @param  list<array{0: Conversation, 1: array<string, mixed>}>  $overrides
 */
function rebuildActivityMetrics(array $overrides = []): void
{
    app(RebuildConversationMetrics::class)->handle();

    foreach ($overrides as [$conversation, $metric]) {
        ConversationMetric::query()->where('conversation_id', $conversation->id)->update($metric);
    }
}

/**
 * @param  array<string, mixed>  $input
 */
function activityFilters(User $viewer, array $input, string $timezone = 'America/Mexico_City'): AnalyticsFilters
{
    return AnalyticsFilters::from($input, $viewer, app(AnalyticsScope::class), $timezone);
}

test('the activity buckets add up to the summary and leave unmeasured averages empty', function () {
    $local = fn (string $time): CarbonImmutable => CarbonImmutable::parse($time, 'America/Mexico_City')->utc();
    $fast = activityConversation($this->sales, $this->ana, $local('2026-10-19 10:00'));
    $slow = activityConversation($this->sales, $this->ana, $local('2026-10-19 11:00'));
    $unanswered = activityConversation($this->support, $this->carla, $local('2026-10-20 09:00'));
    activityConversation($this->support, null, $local('2026-10-21 08:00'));
    // Previous period: never in the buckets.
    activityConversation($this->support, null, $local('2026-10-10 08:00'));
    rebuildActivityMetrics([
        [$fast, ['first_assigned_at' => $local('2026-10-19 10:01'), 'seconds_to_first_human_response' => 60]],
        [$slow, ['first_assigned_at' => $local('2026-10-19 11:05'), 'seconds_to_first_human_response' => 181]],
        [$unanswered, ['first_assigned_at' => $local('2026-10-20 09:10')]],
    ]);
    $admin = User::factory()->administrator()->create();
    $filters = activityFilters($admin, ['period' => '7d']);

    $analytics = app(OperationalAnalytics::class);
    $activity = $analytics->activity($admin, $filters);
    $summary = $analytics->summary($admin, $filters);
    $points = collect($activity['points'])->keyBy('key');

    expect($activity['granularity'])->toBe('day')
        ->and($activity['points'])->toHaveCount(7)
        ->and($points->sum('received'))->toBe($summary['received']['value'])->toBe(4)
        ->and($points->sum('attended'))->toBe($summary['attended']['value'])->toBe(3)
        ->and($points->sum('first_response_samples'))->toBe($summary['first_response_seconds']['samples'])
        ->and($points['2026-10-19'])->toBe(['key' => '2026-10-19', 'label' => '19 oct', 'received' => 2, 'attended' => 2, 'first_response_seconds' => 121, 'first_response_samples' => 2])
        // Attended but never answered: the average is missing, not 0.
        ->and($points['2026-10-20']['first_response_seconds'])->toBeNull()
        ->and($points['2026-10-20']['first_response_samples'])->toBe(0)
        // A day without conversations is a real 0 for the counts.
        ->and($points['2026-10-16'])->toMatchArray(['received' => 0, 'attended' => 0, 'first_response_seconds' => null]);
});

test('a conversation received late at night falls on its local day', function () {
    // 23:30 local on 20 October is 05:30 UTC on the 21st.
    activityConversation($this->sales, $this->ana, CarbonImmutable::parse('2026-10-20 23:30', 'America/Mexico_City')->utc());
    rebuildActivityMetrics();
    $admin = User::factory()->administrator()->create();

    $points = collect(app(OperationalAnalytics::class)->activity($admin, activityFilters($admin, ['period' => '7d']))['points'])->keyBy('key');

    expect($points['2026-10-20']['received'])->toBe(1)
        ->and($points['2026-10-21']['received'])->toBe(0);
});

test('a period of one day is charted per local hour', function () {
    activityConversation($this->sales, $this->ana, CarbonImmutable::parse('2026-10-21 09:15', 'America/Mexico_City')->utc());
    activityConversation($this->sales, $this->ana, CarbonImmutable::parse('2026-10-21 00:05', 'America/Mexico_City')->utc());
    // Yesterday 23:59 local: outside "today" although it is the same UTC date.
    activityConversation($this->sales, $this->ana, CarbonImmutable::parse('2026-10-20 23:59', 'America/Mexico_City')->utc());
    rebuildActivityMetrics();
    $admin = User::factory()->administrator()->create();

    $activity = app(OperationalAnalytics::class)->activity($admin, activityFilters($admin, ['period' => 'today']));
    $points = collect($activity['points'])->keyBy('key');

    expect($activity['granularity'])->toBe('hour')
        ->and($activity['points'])->toHaveCount(24)
        ->and($activity['points'][0]['label'])->toBe('00:00')
        ->and($points['2026-10-21T09:00']['received'])->toBe(1)
        ->and($points['2026-10-21T00:00']['received'])->toBe(1)
        ->and($points->sum('received'))->toBe(2);

    $this->actingAs($admin)
        ->get(route('reports.index', ['period' => 'yesterday']))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('analytics.activity.granularity', 'hour')
            ->where('analytics.activity.points', fn ($points) => collect($points)->firstWhere('key', '2026-10-20T23:00')['received'] === 1)));
});

test('hour buckets follow daylight saving changes without losing conversations', function () {
    app(PlatformSettings::class)->update(['general' => ['timezone' => 'America/New_York']]);
    $this->travelTo(CarbonImmutable::parse('2026-11-01 18:00', 'America/New_York')->utc());
    // 01:30 happens twice on 1 November 2026 (EDT, then EST).
    activityConversation($this->sales, $this->ana, CarbonImmutable::parse('2026-11-01 05:30', 'UTC'));
    activityConversation($this->sales, $this->ana, CarbonImmutable::parse('2026-11-01 06:30', 'UTC'));
    activityConversation($this->sales, $this->ana, CarbonImmutable::parse('2026-11-01 23:30', 'America/New_York')->utc());
    rebuildActivityMetrics();
    $admin = User::factory()->administrator()->create();
    $analytics = app(OperationalAnalytics::class);

    $fallBack = $analytics->activity($admin, activityFilters($admin, ['period' => 'today'], 'America/New_York'));
    $points = collect($fallBack['points'])->keyBy('key');

    expect($fallBack['points'])->toHaveCount(24)
        ->and($points['2026-11-01T01:00']['received'])->toBe(2)
        ->and($points['2026-11-01T23:00']['received'])->toBe(1)
        ->and($points->sum('received'))->toBe(3);

    $springForward = $analytics->activity($admin, activityFilters($admin, ['period' => 'custom', 'from' => '2026-03-08', 'to' => '2026-03-08'], 'America/New_York'));

    expect($springForward['granularity'])->toBe('hour')
        ->and(collect($springForward['points'])->pluck('label')->all())->not->toContain('02:00')
        ->and($springForward['points'])->toHaveCount(23);
});

test('the activity only aggregates the viewer analytics reach', function () {
    $now = CarbonImmutable::now();
    activityConversation($this->sales, $this->ana, $now->subDay());
    activityConversation($this->support, $this->carla, $now->subDay());
    activityConversation($this->support, $this->carla, $now->subDay());
    rebuildActivityMetrics();
    $supervisor = User::factory()->supervisor()->inDepartments($this->sales)->create();
    $analytics = app(OperationalAnalytics::class);

    expect(collect($analytics->activity($supervisor, activityFilters($supervisor, ['period' => '7d']))['points'])->sum('received'))->toBe(1)
        ->and(collect($analytics->activity($this->carla, activityFilters($this->carla, ['period' => '7d']))['points'])->sum('received'))->toBe(2);
});
