<?php

use App\Enums\ResponseCycleKind;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\ResponseCycle;
use App\Models\User;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\AnalyticsScope;
use App\Services\Analytics\ServiceLevelAnalytics;
use App\Services\PlatformSettings;
use Carbon\CarbonImmutable;

/*
| Service level compliance from the response cycles: frozen objectives,
| breaches counted even when nobody answered, and no percentage without data.
*/

beforeEach(function () {
    // Wednesday 2026-10-21 12:00 local time.
    $this->now = CarbonImmutable::parse('2026-10-21 12:00', 'America/Mexico_City')->utc();
    $this->travelTo($this->now);
    $this->sales = Department::factory()->create(['name' => 'Ventas']);
    $this->support = Department::factory()->create(['name' => 'Soporte']);
    $this->connection = Connection::factory()->operational()->create();
    $this->ana = User::factory()->agent()->inDepartments($this->sales)->create(['name' => 'Ana']);
    $this->beto = User::factory()->agent()->inDepartments($this->sales)->create(['name' => 'Beto']);
    $this->admin = User::factory()->administrator()->create();
});

/**
 * A response cycle started some minutes ago (objective in seconds, or none).
 *
 * @param  array<string, mixed>  $attributes
 */
function slaCycle(Department $department, ?User $assignee, int $minutesAgo, ?int $target, array $attributes = []): ResponseCycle
{
    $factory = Conversation::factory()->for(test()->connection, 'channelConnection')->inDepartment($department);
    $conversation = ($assignee === null ? $factory : $factory->assignedTo($assignee))->create();
    $startedAt = test()->now->subMinutes($minutesAgo);

    return ResponseCycle::factory()->create([
        'conversation_id' => $conversation->id,
        'started_at' => $startedAt,
        'target_seconds' => $target,
        'business_hours_only' => false,
        'due_at' => $target === null ? null : $startedAt->addSeconds($target),
        ...$attributes,
    ]);
}

/**
 * Attributes of a cycle answered after some seconds.
 *
 * @return array<string, mixed>
 */
function slaAnswered(User $responder, int $minutesAgo, int $afterSeconds): array
{
    $respondedAt = test()->now->subMinutes($minutesAgo)->addSeconds($afterSeconds);

    return [
        'responded_at' => $respondedAt,
        'ended_at' => $respondedAt,
        'responder_user_id' => $responder->id,
        'outcome' => ResponseCycle::OUTCOME_ANSWERED,
        'response_seconds' => $afterSeconds,
        'business_response_seconds' => $afterSeconds,
        'active' => null,
    ];
}

function slaFilters(User $viewer, array $input = ['period' => '7d']): AnalyticsFilters
{
    return AnalyticsFilters::from($input, $viewer, app(AnalyticsScope::class), 'America/Mexico_City');
}

/**
 * The reference set: one cycle of each kind of outcome.
 */
function slaReferenceCycles(): void
{
    $test = test();
    // Met: answered in 1 min against 5 min.
    slaCycle($test->sales, $test->ana, 120, 300, slaAnswered($test->ana, 120, 60));
    // Breached and answered late by Beto while Ana was responsible.
    slaCycle($test->sales, $test->ana, 110, 300, [...slaAnswered($test->beto, 110, 600), 'breached_at' => $test->now->subMinutes(105), 'breach_user_id' => $test->ana->id, 'exceeded_seconds' => 300]);
    // Breached and still unanswered, waiting in a queue.
    slaCycle($test->support, null, 100, 300, ['breached_at' => $test->now->subMinutes(95), 'breach_user_id' => null, 'kind' => ResponseCycleKind::Response]);
    // Past its deadline but not stamped yet by the evaluator.
    slaCycle($test->support, $test->beto, 60, 300);
    // Still open inside its deadline: not judged.
    slaCycle($test->sales, $test->ana, 1, 300);
    // No objective: outside compliance, although answered.
    slaCycle($test->sales, $test->ana, 90, null, slaAnswered($test->ana, 90, 120));
    // Closed before the deadline without an answer: neither met nor breached.
    slaCycle($test->sales, $test->ana, 80, 3600, ['ended_at' => $test->now->subMinutes(70), 'outcome' => ResponseCycle::OUTCOME_CLOSED, 'active' => null]);
}

test('each cycle falls in exactly one group and compliance only rates the judged ones', function () {
    slaReferenceCycles();

    $sla = app(ServiceLevelAnalytics::class)->compliance($this->admin, slaFilters($this->admin));

    expect($sla['totals'])->toBe([
        'cycles' => 7,
        'met' => 1,
        // Answered late, unanswered and stamped, and unanswered past the deadline.
        'breached' => 3,
        'in_progress' => 1,
        'closed_unanswered' => 1,
        'unmeasured' => 1,
        'samples' => 4,
        'rate' => 25.0,
    ])
        // Minimum, maximum and average of the time between a customer message and its answer.
        ->and($sla['response_time'])->toBe(['avg' => 260, 'min' => 60, 'max' => 600, 'samples' => 3])
        ->and(collect($sla['kinds'])->firstWhere('kind', 'response'))->toMatchArray(['cycles' => 1, 'breached' => 1, 'samples' => 1, 'rate' => 0.0])
        ->and(collect($sla['by_department'])->pluck('breached', 'name')->all())->toBe(['Soporte' => 2, 'Ventas' => 1]);
});

test('the average overrun of a group never mixes business seconds with calendar seconds', function () {
    $late = fn (int $minutesAgo, bool $businessOnly, ?int $exceeded): ResponseCycle => slaCycle($this->sales, $this->ana, $minutesAgo, 300, [
        ...slaAnswered($this->ana, $minutesAgo, 900),
        'business_hours_only' => $businessOnly,
        'breached_at' => $this->now->subMinutes($minutesAgo)->addSeconds(300),
        'breach_user_id' => $this->ana->id,
        'exceeded_seconds' => $exceeded,
    ]);
    // Objectives in attention hours: 10 and 20 business minutes over.
    $late(200, true, 600);
    $late(190, true, 1200);
    // Objective in calendar time: 16 hours over (a night in between).
    $late(180, false, 57600);
    // Overrun unknown (too long a span for the calendar): in no average.
    $late(170, true, null);
    // Only calendar objectives in Soporte.
    slaCycle($this->support, null, 160, 300, [...slaAnswered($this->beto, 160, 900), 'breached_at' => $this->now->subMinutes(155), 'exceeded_seconds' => 600]);

    $groups = collect(app(ServiceLevelAnalytics::class)->compliance($this->admin, slaFilters($this->admin))['by_department'])->keyBy('name');

    expect($groups['Ventas'])
        ->breached->toBe(4)
        ->exceeded_business_seconds->toBe(900)
        ->exceeded_calendar_seconds->toBe(57600)
        ->not->toHaveKey('exceeded_seconds')
        ->and($groups['Soporte'])
        ->exceeded_business_seconds->toBeNull()
        ->exceeded_calendar_seconds->toBe(600);
});

test('without judged cycles there is no percentage, never a perfect compliance', function () {
    slaCycle($this->sales, $this->ana, 90, null, slaAnswered($this->ana, 90, 120));
    slaCycle($this->sales, $this->ana, 1, 300);

    $sla = app(ServiceLevelAnalytics::class)->compliance($this->admin, slaFilters($this->admin));

    expect($sla['totals'])
        ->samples->toBe(0)
        ->rate->toBeNull()
        ->and($sla['previous'])->toBe(['rate' => null, 'samples' => 0]);
});

test('history is not reconstructed: the figures say since when they exist', function () {
    $service = app(ServiceLevelAnalytics::class);

    expect($service->compliance($this->admin, slaFilters($this->admin)))
        ->available_since->toBeNull()
        ->covers_period->toBeFalse();

    slaCycle($this->sales, $this->ana, 120, 300, slaAnswered($this->ana, 120, 60));

    expect($service->compliance($this->admin, slaFilters($this->admin, ['period' => '30d'])))
        ->available_since->toBe('2026-10-21')
        ->covers_period->toBeFalse()
        ->and($service->compliance($this->admin, slaFilters($this->admin, ['period' => 'today']))['covers_period'])->toBeTrue();
});

test('changing the objective later never rewrites past compliance', function () {
    slaReferenceCycles();
    $service = app(ServiceLevelAnalytics::class);
    $before = $service->compliance($this->admin, slaFilters($this->admin))['totals'];

    app(PlatformSettings::class)->update(['sla' => ['first_response_minutes' => 600]]);

    expect($service->compliance($this->admin, slaFilters($this->admin))['totals'])->toBe($before);
});

test('a met cycle belongs to who answered and a breach to who was responsible then', function () {
    slaReferenceCycles();

    $owners = app(ServiceLevelAnalytics::class)->byOwner($this->admin, slaFilters($this->admin));

    expect($owners[$this->ana->id])->toBe(['met' => 1, 'breached' => 1, 'late_answers' => 0])
        // The unstamped overdue cycle is Beto's (assigned); his late answer is informative only.
        ->and($owners[$this->beto->id])->toBe(['met' => 0, 'breached' => 1, 'late_answers' => 1])
        // The breach in the queue belongs to nobody.
        ->and($owners[0])->toBe(['met' => 0, 'breached' => 1, 'late_answers' => 0]);
});

test('reach and filters restrict the cycles, and the status filter does not apply to them', function () {
    slaReferenceCycles();
    $service = app(ServiceLevelAnalytics::class);
    $ana = $this->ana->refresh();
    $lead = User::factory()->supervisor()->inDepartments($this->support)->create();

    expect($service->compliance($ana, slaFilters($ana))['totals'])
        // Her met cycle and the breach under her responsibility; not Beto's nor the queue's.
        ->met->toBe(1)
        ->breached->toBe(1)
        ->and($service->compliance($lead, slaFilters($lead))['totals'])->toMatchArray(['cycles' => 2, 'breached' => 2, 'met' => 0])
        ->and(array_column($service->compliance($lead, slaFilters($lead))['by_department'], 'name'))->toBe(['Soporte'])
        ->and($service->compliance($this->admin, slaFilters($this->admin, ['period' => '7d', 'department' => $this->support->id]))['totals']['cycles'])->toBe(2)
        ->and($service->compliance($this->admin, slaFilters($this->admin, ['period' => '7d', 'agent' => $this->beto->id]))['totals'])->toMatchArray(['met' => 0, 'breached' => 1])
        ->and($service->compliance($this->admin, slaFilters($this->admin, ['period' => '7d', 'status' => 'closed']))['totals']['cycles'])->toBe(7);
});

test('the cycles waiting now past their deadline are counted per assigned person', function () {
    slaReferenceCycles();

    $overdue = app(ServiceLevelAnalytics::class)->overdueNow($this->admin, slaFilters($this->admin), $this->now);

    expect($overdue)->toEqualCanonicalizing([0 => 1, $this->beto->id => 1]);
});
