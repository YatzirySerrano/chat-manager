<?php

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\ConversationCategory;
use App\Models\Department;
use App\Models\ResponseCycle;
use App\Models\User;
use App\Services\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The SLA on screen: the `sla` key of each conversation row (one query per
| page) and the «Monitoreo de SLA» of «Seguimiento de atención» (open cycles
| and breach history), always within the viewer's reach.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-09 16:00:00', 'UTC'));
    app(PlatformSettings::class)->update(['general' => ['timezone' => 'UTC']]);

    $this->support = Department::factory()->create(['name' => 'Soporte']);
    $this->sales = Department::factory()->create(['name' => 'Ventas']);
    $this->connection = Connection::factory()->operational()->create(['name' => 'Línea principal']);
    $this->admin = User::factory()->administrator()->create(['name' => 'Admin']);
    $this->ana = User::factory()->agent()->inDepartments($this->support)->create(['name' => 'Ana']);
    $this->beto = User::factory()->agent()->inDepartments($this->sales)->create(['name' => 'Beto']);
});

/**
 * An open cycle that started the given minutes ago, on a conversation of
 * the department attended by the agent (or queued). Objective in calendar
 * minutes (warning at 80 %), or none.
 *
 * @param  array<string, mixed>  $conversationAttributes
 */
function monitoredCycle(Department $department, ?User $agent, int $startedMinutesAgo, ?int $targetMinutes = 10, array $conversationAttributes = [], ?string $contact = null): ResponseCycle
{
    $factory = Conversation::factory()->inDepartment($department);
    $conversation = ($agent === null ? $factory : $factory->assignedTo($agent))->create([
        'connection_id' => test()->connection->id,
        'last_inbound_at' => now()->subMinutes($startedMinutesAgo),
        ...$conversationAttributes,
    ]);

    if ($contact !== null) {
        $conversation->contact->forceFill(['name' => $contact])->save();
    }

    $cycle = ResponseCycle::factory()->state(['started_at' => now()->subMinutes($startedMinutesAgo)]);

    return ($targetMinutes === null ? $cycle : $cycle->withTarget($targetMinutes * 60))->create(['conversation_id' => $conversation->id]);
}

/**
 * Props of the attention page fetched as the browser does for deferred
 * props (a partial reload).
 *
 * @param  array<string, mixed>  $query
 * @return array<string, mixed>
 */
function slaMonitorProps(User $viewer, array $query = [], string $props = 'sla,slaBreaches,slaFilters'): array
{
    return test()->actingAs($viewer)
        ->get(route('attention.index', $query), [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'attention/Index',
            'X-Inertia-Partial-Data' => $props,
            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
        ])
        ->assertOk()
        ->json('props');
}

test('the monitor counts each state and lists the most urgent request first', function () {
    monitoredCycle($this->support, $this->ana, 2, contact: 'En tiempo');
    monitoredCycle($this->support, $this->ana, 9, contact: 'Por vencer');
    monitoredCycle($this->support, $this->ana, 30, contact: 'Vencida hace mucho');
    monitoredCycle($this->sales, null, 12, contact: 'Vencida en cola');
    monitoredCycle($this->support, $this->ana, 50, null, contact: 'Sin objetivo');

    $sla = slaMonitorProps($this->admin)['sla'];

    expect($sla['summary'])->toBe(['on_time' => 1, 'warning' => 1, 'breached' => 2, 'unmeasured' => 1])
        ->and($sla['total'])->toBe(5)
        ->and(array_column($sla['rows'], 'contact'))->toBe(['Vencida hace mucho', 'Vencida en cola', 'Por vencer', 'En tiempo', 'Sin objetivo'])
        ->and($sla['rows'][0])->toMatchArray([
            'agent' => ['id' => $this->ana->id, 'name' => 'Ana'],
            'kind_label' => 'Primera respuesta',
            'status_label' => 'En atención',
            'started_at' => '2026-11-09T15:30:00+00:00',
        ])
        ->and($sla['rows'][0]['department']['name'])->toBe('Soporte')
        ->and($sla['rows'][0]['connection']['name'])->toBe('Línea principal')
        ->and($sla['rows'][0]['sla'])->toMatchArray([
            'kind' => 'first_response',
            'due_at' => '2026-11-09T15:40:00+00:00',
            'warn_at' => '2026-11-09T15:38:00+00:00',
            'target_seconds' => 600,
            'business_hours_only' => false,
        ])
        ->and($sla['rows'][1]['agent'])->toBeNull()
        ->and($sla['rows'][4]['sla'])->toBeNull();
});

test('the first load defers the monitor and delivers the server clock and the filter options', function () {
    ConversationCategory::factory()->create(['name' => 'Garantías']);

    $this->actingAs($this->admin)->get(route('attention.index', ['tab' => 'sla']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('attention/Index')
            ->where('tab', 'sla')
            ->where('serverTime', '2026-11-09T16:00:00+00:00')
            ->missing('sla')
            ->missing('slaBreaches')
            ->where('slaFilters.from', '2026-11-03')
            ->where('slaFilters.to', '2026-11-09')
            ->has('slaOptions.departments', 2)
            ->where('slaOptions.categories.0.name', 'Garantías')
            ->where('slaOptions.connections.0.name', 'Línea principal')
            ->has('slaOptions.states', 4)
            ->where('can.settings', true)
            ->where('settingsUrl', route('admin.settings.edit'))
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('sla.summary.breached', 0)
                ->where('sla.configured', false)
                ->has('slaBreaches.rows', 0)));
});

test('a viewer only sees the requests of the conversations within their reach', function () {
    $supervisor = User::factory()->supervisor()->inDepartments($this->support)->create();
    monitoredCycle($this->support, $this->ana, 30, contact: 'De soporte');
    monitoredCycle($this->sales, $this->beto, 30, contact: 'De ventas');
    $own = conversationUser([Permission::ViewAttention, Permission::ViewConversations, Permission::ReplyConversations], DataScope::Assigned, [$this->support]);
    monitoredCycle($this->support, $own, 30, contact: 'Propia');

    $forSupervisor = slaMonitorProps($supervisor, ['sla_from' => '2026-11-01', 'sla_to' => '2026-11-09']);
    $forOwn = slaMonitorProps($own, ['sla_from' => '2026-11-01', 'sla_to' => '2026-11-09']);

    // Breaches of the three cycles, recorded as the evaluator leaves them.
    ResponseCycle::query()->update(['breached_at' => DB::raw('due_at'), 'breach_user_id' => DB::raw('assigned_user_id')]);

    expect(collect($forSupervisor['sla']['rows'])->pluck('contact')->sort()->values()->all())->toBe(['De soporte', 'Propia'])
        ->and($forSupervisor['sla']['summary']['breached'])->toBe(2)
        ->and(array_column($forOwn['sla']['rows'], 'contact'))->toBe(['Propia'])
        ->and(collect(slaMonitorProps($supervisor, ['sla_from' => '2026-11-01', 'sla_to' => '2026-11-09'])['slaBreaches']['rows'])->pluck('contact')->sort()->values()->all())->toBe(['De soporte', 'Propia'])
        ->and(array_column(slaMonitorProps($own, ['sla_from' => '2026-11-01', 'sla_to' => '2026-11-09'])['slaBreaches']['rows'], 'contact'))->toBe(['Propia'])
        // The options never offer what the viewer does not reach.
        ->and(array_column(slaMonitorProps($supervisor, [], 'slaOptions')['slaOptions']['departments'], 'name'))->toBe(['Soporte']);
});

test('the monitor filters by state, department, category, agent and connection', function () {
    $category = ConversationCategory::factory()->create(['name' => 'Garantías']);
    $otherConnection = Connection::factory()->operational()->create();
    monitoredCycle($this->support, $this->ana, 30, contact: 'Vencida de Ana');
    monitoredCycle($this->support, $this->ana, 2, contact: 'En tiempo de Ana');
    monitoredCycle($this->sales, $this->beto, 9, contact: 'Por vencer de Beto');
    $categorized = monitoredCycle($this->sales, $this->beto, 1, contact: 'Con categoría');
    $categorized->forceFill(['category_id' => $category->id])->save();
    $elsewhere = monitoredCycle($this->sales, $this->beto, 1, contact: 'Otra conexión');
    $elsewhere->forceFill(['connection_id' => $otherConnection->id])->save();

    $contacts = fn (array $query): array => collect(slaMonitorProps($this->admin, $query)['sla']['rows'])->pluck('contact')->sort()->values()->all();

    expect($contacts(['sla_state' => 'breached']))->toBe(['Vencida de Ana'])
        ->and($contacts(['sla_state' => 'warning']))->toBe(['Por vencer de Beto'])
        ->and($contacts(['sla_state' => 'on_time']))->toBe(['Con categoría', 'En tiempo de Ana', 'Otra conexión'])
        ->and($contacts(['sla_department' => $this->support->id]))->toBe(['En tiempo de Ana', 'Vencida de Ana'])
        ->and($contacts(['sla_agent' => $this->beto->id, 'sla_state' => 'on_time']))->toBe(['Con categoría', 'Otra conexión'])
        ->and($contacts(['sla_category' => $category->id]))->toBe(['Con categoría'])
        ->and($contacts(['sla_connection' => $otherConnection->id]))->toBe(['Otra conexión'])
        // The counters follow the dimension filters, not the state filter.
        ->and(slaMonitorProps($this->admin, ['sla_department' => $this->support->id, 'sla_state' => 'breached'])['sla']['summary'])
        ->toBe(['on_time' => 1, 'warning' => 0, 'breached' => 1, 'unmeasured' => 0]);
});

test('invalid filters are rejected by the server', function (array $query, string $field) {
    $this->actingAs($this->admin)->get(route('attention.index', $query))->assertSessionHasErrors($field);
})->with([
    'unknown state' => [['sla_state' => 'late'], 'sla_state'],
    'department that is not a number' => [['sla_department' => 'abc'], 'sla_department'],
    'malformed date' => [['sla_from' => '09/11/2026'], 'sla_from'],
    'range upside down' => [['sla_from' => '2026-11-09', 'sla_to' => '2026-11-01'], 'sla_to'],
]);

test('the open requests are paginated on the server', function () {
    foreach (range(1, 27) as $minutes) {
        monitoredCycle($this->support, $this->ana, $minutes);
    }

    $first = slaMonitorProps($this->admin)['sla'];
    $second = slaMonitorProps($this->admin, ['sla_page' => 2])['sla'];
    $beyond = slaMonitorProps($this->admin, ['sla_page' => 9])['sla'];

    expect($first)->total->toBe(27)->last_page->toBe(2)->page->toBe(1)
        ->and($first['rows'])->toHaveCount(25)
        ->and($second['rows'])->toHaveCount(2)
        ->and($beyond['page'])->toBe(2)
        ->and(array_intersect(array_column($first['rows'], 'id'), array_column($second['rows'], 'id')))->toBe([]);
});

test('the breach history tells which objective applied, who was responsible and what happened afterwards', function () {
    $answered = monitoredCycle($this->support, $this->ana, 60, contact: 'Respondida tarde');
    $answered->forceFill([
        'breached_at' => $answered->due_at, 'breach_user_id' => $this->ana->id,
        'responded_at' => now()->subMinutes(20), 'ended_at' => now()->subMinutes(20), 'responder_user_id' => $this->beto->id,
        'outcome' => ResponseCycle::OUTCOME_ANSWERED, 'response_seconds' => 2400, 'exceeded_seconds' => 1800, 'active' => null,
    ])->save();

    $closed = monitoredCycle($this->support, null, 50, contact: 'Cerrada sin respuesta');
    $closed->forceFill([
        'breached_at' => $closed->due_at, 'breach_user_id' => null,
        'ended_at' => now()->subMinutes(10), 'outcome' => ResponseCycle::OUTCOME_CLOSED, 'exceeded_seconds' => 1800, 'active' => null,
    ])->save();

    $open = monitoredCycle($this->support, $this->ana, 25, contact: 'Sigue abierta');
    $open->forceFill(['breached_at' => $open->due_at, 'breach_user_id' => $this->ana->id])->save();

    // Outside the range, and never breached: not listed.
    $old = monitoredCycle($this->support, $this->ana, 60 * 24 * 20, contact: 'Antigua');
    $old->forceFill(['breached_at' => $old->due_at, 'active' => null, 'outcome' => ResponseCycle::OUTCOME_CLOSED, 'ended_at' => now()])->save();
    monitoredCycle($this->support, $this->ana, 1, contact: 'En tiempo');

    $history = slaMonitorProps($this->admin)['slaBreaches'];
    $rows = collect($history['rows'])->keyBy('contact');

    expect($history)->total->toBe(3)->from->toBe('2026-11-03')->to->toBe('2026-11-09')
        // Newest breach first.
        ->and(array_column($history['rows'], 'contact'))->toBe(['Sigue abierta', 'Cerrada sin respuesta', 'Respondida tarde'])
        ->and($rows['Respondida tarde'])->toMatchArray([
            'kind_label' => 'Primera respuesta',
            'target_seconds' => 600,
            'business_hours_only' => false,
            'started_at' => '2026-11-09T15:00:00+00:00',
            'due_at' => '2026-11-09T15:10:00+00:00',
            'responsible' => 'Ana',
            'exceeded_seconds' => 1800,
            'outcome' => 'answered',
            'responder' => 'Beto',
            'responded_at' => '2026-11-09T15:40:00+00:00',
        ])
        ->and($rows['Cerrada sin respuesta'])->toMatchArray(['responsible' => null, 'outcome' => 'closed', 'ended_at' => '2026-11-09T15:50:00+00:00'])
        // Still open: the time over the deadline is computed now.
        ->and($rows['Sigue abierta'])->toMatchArray(['outcome' => 'open', 'exceeded_seconds' => 900, 'responder' => null])
        ->and(slaMonitorProps($this->admin, ['sla_agent' => $this->beto->id])['slaBreaches']['total'])->toBe(0)
        ->and(slaMonitorProps($this->admin, ['sla_from' => '2026-10-01', 'sla_to' => '2026-11-09'])['slaBreaches']['total'])->toBe(4);
});

test('the monitor tells whether any response objective is configured', function () {
    expect(slaMonitorProps($this->admin)['sla']['configured'])->toBeFalse();

    $this->sales->forceFill(['service_rules' => ['sla.response_minutes' => 15]])->save();

    expect(slaMonitorProps($this->admin)['sla']['configured'])->toBeTrue();
});

test('the monitor is refused without attention.view, and the settings link is only for who may edit settings', function () {
    $supervisor = User::factory()->supervisor()->inDepartments($this->support)->create();

    $this->actingAs($this->ana)->get(route('attention.index', ['tab' => 'sla']))->assertForbidden();
    $this->actingAs($this->ana)->get(route('attention.index', ['sla_state' => 'breached']))->assertForbidden();

    $this->actingAs($supervisor)->get(route('attention.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can.settings', false)->where('settingsUrl', null)->where('tab', 'attention'));
});

test('each conversation row carries its open SLA, loaded with one query for the whole page', function () {
    $rowsFor = function (int $expected): array {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $rows = $this->actingAs($this->admin)
            ->get(route('conversations.index', ['view' => 'all']), [
                'X-Inertia' => 'true',
                'X-Inertia-Partial-Component' => 'conversations/Index',
                'X-Inertia-Partial-Data' => 'conversations',
                'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
            ])
            ->assertOk()
            ->assertJsonCount($expected, 'props.conversations.data')
            ->json('props.conversations.data');

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return [$rows, count($queries), collect($queries)->filter(fn (array $query): bool => str_contains($query['query'], 'response_cycles'))->count()];
    };

    $measured = monitoredCycle($this->support, $this->ana, 9);
    monitoredCycle($this->support, $this->ana, 3, null);
    $answered = monitoredCycle($this->support, $this->ana, 30);
    $answered->forceFill(['active' => null, 'outcome' => ResponseCycle::OUTCOME_ANSWERED, 'ended_at' => now()])->save();
    Conversation::factory()->inDepartment($this->support)->create(['connection_id' => $this->connection->id]);

    $rowsFor(4); // warms per-process caches (permissions, settings)
    [$rows, $few, $cycleQueriesFew] = $rowsFor(4);
    $byId = collect($rows)->keyBy('id');

    expect($byId[$measured->conversation_id]['sla'])->toBe([
        'kind' => 'first_response',
        'kind_label' => 'Primera respuesta',
        'started_at' => '2026-11-09T15:51:00+00:00',
        'warn_at' => '2026-11-09T15:59:00+00:00',
        'due_at' => '2026-11-09T16:01:00+00:00',
        'target_seconds' => 600,
        'business_hours_only' => false,
        'breached' => false,
    ])
        // Without objective, already answered or nobody waiting: no badge.
        ->and($byId->except($measured->conversation_id)->pluck('sla')->filter()->all())->toBe([])
        ->and($cycleQueriesFew)->toBe(1);

    foreach (range(1, 10) as $minutes) {
        monitoredCycle($this->support, $this->ana, $minutes);
    }

    [, $many, $cycleQueriesMany] = $rowsFor(14);

    expect($many)->toBe($few)
        ->and($cycleQueriesMany)->toBe(1);
});

test('the open conversation carries its SLA too', function () {
    $cycle = monitoredCycle($this->support, $this->ana, 12);
    $cycle->forceFill(['breached_at' => $cycle->due_at])->save();

    $this->actingAs($this->ana)->get(route('conversations.index', ['c' => $cycle->conversation_id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation.sla.breached', true)
            ->where('conversation.sla.due_at', '2026-11-09T15:58:00+00:00'));
});
