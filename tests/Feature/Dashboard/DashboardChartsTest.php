<?php

use App\Actions\Analytics\RebuildConversationMetrics;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\ConversationMetric;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Reports\ReportRegistry;
use App\Services\DashboardMetrics;
use App\Services\Exports\ReportExporter;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Dashboard charts (deferred "charts" prop): analytics reach of the viewer,
| visibility per chart decided by the server, and the export drawing the
| same data as the screen.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00', 'America/Mexico_City')->utc());
    $this->sales = Department::factory()->create(['name' => 'Ventas']);
    $this->support = Department::factory()->create(['name' => 'Soporte']);
    $this->connection = Connection::factory()->operational()->create();
    $this->ana = User::factory()->agent()->inDepartments($this->sales)->create(['name' => 'Ana Ventas']);
    $this->carla = User::factory()->agent()->inDepartments($this->support)->create(['name' => 'Carla Soporte']);
});

function chartConversations(Department $department, ?User $agent, int $count = 1): void
{
    $factory = Conversation::factory()->for(test()->connection, 'channelConnection')->inDepartment($department);
    $factory = $agent === null ? $factory : $factory->assignedTo($agent);
    $factory->count($count)->create(['created_at' => CarbonImmutable::now()->subDay(), 'updated_at' => CarbonImmutable::now()->subDay()]);
}

/**
 * @return array<string, mixed>
 */
function dashboardCharts(User $viewer, array $query = []): array
{
    $charts = null;

    test()->actingAs($viewer)
        ->get(route('dashboard', $query))
        ->assertInertia(function (Assert $page) use (&$charts) {
            $page->missing('charts')->loadDeferredProps('charts', function (Assert $reload) use (&$charts) {
                $charts = $reload->toArray()['props']['charts'];
            });
        });

    return $charts;
}

test('a global viewer gets every chart, adding up to the same totals', function () {
    chartConversations($this->sales, $this->ana, 2);
    chartConversations($this->support, $this->carla);
    chartConversations($this->support, null);
    app(RebuildConversationMetrics::class)->handle();
    ConversationMetric::query()->where('agent_id', $this->ana->id)->update(['first_assigned_at' => now()]);

    $charts = dashboardCharts(User::factory()->administrator()->create(), ['period' => '7d']);

    expect($charts['activity']['granularity'])->toBe('day')
        ->and(collect($charts['activity']['points'])->sum('received'))->toBe(4)
        ->and(collect($charts['activity']['points'])->sum('attended'))->toBe(2)
        ->and($charts['channels'])->toBe([['channel' => 'whatsapp', 'label' => 'WhatsApp', 'received' => 4, 'attended' => 2]])
        ->and(collect($charts['statuses'])->sum('count'))->toBe(4)
        // Most received first; ties by id (no agent first), like the reports.
        ->and(collect($charts['agents'])->pluck('name')->all())->toBe(['Ana Ventas', 'Sin agente', 'Carla Soporte'])
        ->and(collect($charts['agents'])->pluck('key')->all())->toBe([(string) $this->ana->id, 'none', (string) $this->carla->id])
        ->and(collect($charts['agents'])->sum('received'))->toBe(4)
        ->and(collect($charts['departments'])->pluck('name')->all())->toBe(['Ventas', 'Soporte'])
        ->and(collect($charts['departments'])->sum('received'))->toBe(4);
});

test('the agents beyond the top eight are summed as one row', function () {
    $agents = User::factory()->agent()->inDepartments($this->sales)->count(10)->create();
    $agents->each(fn (User $agent, int $index) => chartConversations($this->sales, $agent, $index < 8 ? 3 : 1));
    app(RebuildConversationMetrics::class)->handle();
    ConversationMetric::query()->where('agent_id', $agents[8]->id)->update(['seconds_to_first_human_response' => 60]);
    ConversationMetric::query()->where('agent_id', $agents[9]->id)->update(['seconds_to_first_human_response' => 120]);

    $rows = dashboardCharts(User::factory()->administrator()->create())['agents'];

    expect($rows)->toHaveCount(9)
        ->and(collect($rows)->sum('received'))->toBe(26)
        ->and($rows[8])->toBe(['key' => 'others', 'id' => null, 'name' => 'Otros', 'received' => 2, 'attended' => 0, 'first_response_seconds' => 90, 'first_response_samples' => 2]);
});

test('an assigned scope only charts its own conversations, without team charts', function () {
    chartConversations($this->sales, $this->ana, 2);
    chartConversations($this->sales, null);
    chartConversations($this->support, $this->carla);
    app(RebuildConversationMetrics::class)->handle();

    $charts = dashboardCharts($this->ana);

    expect($charts['agents'])->toBeNull()
        ->and($charts['departments'])->toBeNull()
        ->and(collect($charts['activity']['points'])->sum('received'))->toBe(2)
        ->and($charts['channels'][0]['received'])->toBe(2);
});

test('a supervisor charts the agents of its departments, and departments only over several of them', function () {
    chartConversations($this->sales, $this->ana);
    chartConversations($this->support, $this->carla, 2);
    $third = Department::factory()->create(['name' => 'Cobranza']);
    chartConversations($third, null);
    app(RebuildConversationMetrics::class)->handle();

    $single = dashboardCharts(User::factory()->supervisor()->inDepartments($this->sales)->create());

    expect(collect($single['agents'])->pluck('name')->all())->toBe(['Ana Ventas'])
        ->and($single['departments'])->toBeNull();

    $several = dashboardCharts(User::factory()->supervisor()->inDepartments([$this->sales, $this->support])->create());

    expect(collect($several['departments'])->pluck('name')->all())->toBe(['Soporte', 'Ventas'])
        ->and(collect($several['agents'])->pluck('name')->all())->toBe(['Carla Soporte', 'Ana Ventas']);
});

test('team charts require their permissions', function () {
    chartConversations($this->sales, $this->ana);
    app(RebuildConversationMetrics::class)->handle();
    $viewer = User::factory()
        ->withRole(Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewDashboard, Permission::ViewConversations])->create())
        ->create();

    $charts = dashboardCharts($viewer);

    expect($charts['agents'])->toBeNull()
        ->and($charts['departments'])->toBeNull()
        ->and($charts['activity'])->not->toBeNull();
});

test('without traffic on any channel or without conversations.view no chart is drawn', function () {
    $this->connection->forceFill(['is_active' => false])->save();
    $none = ['activity' => null, 'channels' => null, 'statuses' => null, 'agents' => null, 'departments' => null];

    expect(dashboardCharts(User::factory()->administrator()->create()))->toBe($none);

    $this->connection->forceFill(['is_active' => true])->save();
    cache()->flush();
    $withoutConversations = User::factory()
        ->withRole(Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewDashboard, Permission::ViewDepartments, Permission::ViewReports])->create())
        ->create();

    expect(dashboardCharts($withoutConversations))->toBe($none);
});

test('a period without conversations gives empty charts, never fake values', function () {
    $charts = dashboardCharts(User::factory()->administrator()->create(), ['period' => 'today']);

    expect($charts['activity']['granularity'])->toBe('hour')
        ->and($charts['activity']['points'])->toHaveCount(24)
        ->and(collect($charts['activity']['points'])->sum('received'))->toBe(0)
        ->and(collect($charts['activity']['points'])->pluck('first_response_seconds')->unique()->all())->toBe([null])
        ->and($charts['channels'])->toBe([])
        ->and($charts['agents'])->toBe([])
        ->and($charts['departments'])->toBe([])
        ->and(collect($charts['statuses'])->sum('count'))->toBe(0);
});

test('the overview trend of today is hourly', function () {
    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('dashboard', ['period' => 'today']))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('default', fn (Assert $reload) => $reload
            ->has('overview.trend', 24)
            ->where('overview.trend_granularity', 'hour')
            ->where('overview.trend.9.label', '09:00')));
});

test('without dashboard.view the charts are neither computed nor sent', function () {
    $user = User::factory()
        ->withRole(Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewConversations, Permission::ViewReports])->create())
        ->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $this->actingAs($user)
        ->get(route('dashboard'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) Inertia::getVersion(),
            'X-Inertia-Partial-Component' => 'Dashboard',
            'X-Inertia-Partial-Data' => 'charts',
        ])
        ->assertOk()
        ->assertJsonPath('props.charts', null);

    expect(fn () => app(DashboardMetrics::class)->chartsFor($user))->toThrow(AuthorizationException::class);
});

test('the dashboard export draws the same charts as the screen', function () {
    chartConversations($this->sales, $this->ana, 2);
    chartConversations($this->support, $this->carla);
    app(RebuildConversationMetrics::class)->handle();
    ConversationMetric::query()->where('agent_id', $this->ana->id)->update(['first_assigned_at' => now(), 'seconds_to_first_human_response' => 120]);
    $admin = User::factory()->administrator()->create();
    $admin->roles->first()->givePermissionTo(Permission::ExportReports->value);
    $admin->refresh();

    $screen = dashboardCharts($admin, ['period' => '7d']);
    $exporter = app(ReportExporter::class);
    $definition = app(ReportRegistry::class)->find('dashboard');
    $context = $exporter->prepare($definition, $admin, ['period' => '7d']);
    $charts = collect($definition->charts($context))->keyBy('title');
    $sheets = collect($definition->extraSheets($context))->keyBy('title');

    expect($charts->keys()->all())->toBe(['Actividad', 'Por canal', 'Estado de lo recibido', 'Atención por agente', 'Tiempo de primera respuesta', 'Por departamento'])
        ->and($charts['Actividad']->labels)->toBe(array_column($screen['activity']['points'], 'label'))
        ->and($charts['Actividad']->series[0]->values)->toBe(array_column($screen['activity']['points'], 'received'))
        ->and($charts['Actividad']->series[1]->values)->toBe(array_column($screen['activity']['points'], 'attended'))
        ->and($charts['Por canal']->series[0]->values)->toBe(array_column($screen['channels'], 'received'))
        ->and($charts['Estado de lo recibido']->series[0]->values)->toBe(array_column($screen['statuses'], 'count'))
        ->and($charts['Atención por agente']->labels)->toBe(array_column($screen['agents'], 'name'))
        ->and($charts['Por departamento']->series[1]->values)->toBe(array_column($screen['departments'], 'resolved'))
        // Every day of the screen; only the one with measured responses has a value (2 min), the rest are gaps.
        ->and($charts['Tiempo de primera respuesta']->labels)->toBe(array_column($screen['activity']['points'], 'label'))
        ->and($charts['Tiempo de primera respuesta']->series[0]->values)->toBe([null, null, null, null, null, 2.0, null])
        ->and(collect($sheets['Actividad']->rows)->sum('received'))->toBe(3)
        ->and(collect($sheets['Actividad']->rows)->pluck('first_response')->filter()->values()->all())->toBe([2.0])
        ->and(collect($definition->metrics($context))->pluck('label')->take(4)->all())->toBe(['Pendientes', 'En atención', 'Finalizadas hoy', 'Primera respuesta (promedio)']);
});

test('the root account is never revealed to other viewers but its figures still count', function () {
    $root = User::factory()->superAdministrator()->create(['name' => 'Cuenta Raíz']);
    chartConversations($this->sales, $root, 2);
    chartConversations($this->sales, $this->ana);
    chartConversations($this->sales, null);
    app(RebuildConversationMetrics::class)->handle();
    $admin = User::factory()->administrator()->create();

    $agents = dashboardCharts($admin)['agents'];

    expect(collect($agents)->pluck('name')->all())->toBe(['Administración del sistema', 'Sin agente', 'Ana Ventas'])
        ->and(collect($agents)->pluck('key')->all())->toBe(['system', 'none', (string) $this->ana->id])
        ->and($agents[0]['id'])->toBeNull()
        ->and(collect($agents)->sum('received'))->toBe(4);

    $this->actingAs($admin)
        ->get(route('reports.index', ['agent' => $root->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters', [])
            ->where('options.agents', fn ($options) => collect($options)->pluck('id')->doesntContain($root->id))
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('analytics.by_agent', fn ($rows) => collect($rows)->pluck('name')->doesntContain('Cuenta Raíz')
                    && collect($rows)->pluck('id')->doesntContain($root->id)
                    && collect($rows)->sum('received') === 4)));

    expect(collect(dashboardCharts($root)['agents'])->pluck('name')->all())->toBe(['Cuenta Raíz', 'Sin agente', 'Ana Ventas']);
});

test('the analytics export lists a hidden root agent once, under the system name', function () {
    $root = User::factory()->superAdministrator()->create(['name' => 'Cuenta Raíz']);
    chartConversations($this->sales, $root);
    chartConversations($this->sales, null);
    app(RebuildConversationMetrics::class)->handle();
    $admin = User::factory()->administrator()->create();
    $exporter = app(ReportExporter::class);
    $definition = app(ReportRegistry::class)->find('analytics');
    $context = $exporter->prepare($definition, $admin, ['period' => '7d']);

    $names = collect(iterator_to_array($definition->rows($context), false))->pluck('name')->all();

    expect($names)->toEqualCanonicalizing(['Administración del sistema', 'Sin agente']);
});
