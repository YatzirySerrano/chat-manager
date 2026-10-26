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
use App\Reports\Chart;
use App\Reports\ChartSeries;
use App\Reports\ChartType;
use App\Reports\Rendering\ChartGeometry;
use App\Reports\ReportContext;
use App\Reports\ReportRegistry;
use App\Services\Analytics\AnalyticsScope;
use App\Services\DashboardMetrics;
use App\Services\Exports\ReportExporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The dashboard, its export and the reports export agree with each other:
| same filters on every figure, same day, gaps instead of invented values,
| and protected accounts mapped to their own figures.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00', 'America/Mexico_City')->utc());
    $this->sales = Department::factory()->create(['name' => 'Ventas']);
    $this->ana = User::factory()->agent()->inDepartments($this->sales)->create(['name' => 'Ana Ventas']);
    $this->beto = User::factory()->agent()->inDepartments($this->sales)->create(['name' => 'Beto Ventas']);
    $this->primary = Connection::factory()->operational()->create(['name' => 'WhatsApp Ventas']);
    $this->secondary = Connection::factory()->operational()->create(['name' => 'WhatsApp Soporte']);
});

function exportingAdministrator(): User
{
    $admin = User::factory()->administrator()->create();
    $admin->roles->first()->givePermissionTo(Permission::ExportReports->value);

    return $admin->refresh();
}

/**
 * @return array<string, int>
 */
function departmentLoadTotals(User $actor, array $input): array
{
    $definition = app(ReportRegistry::class)->find('dashboard');
    $context = app(ReportExporter::class)->prepare($definition, $actor, $input);
    $rows = collect(iterator_to_array($definition->rows($context), false));
    $metrics = collect($definition->metrics($context))->keyBy('label');

    return [
        'table_pending' => (int) $rows->sum('pending'),
        'table_attending' => (int) $rows->sum('attending'),
        'kpi_pending' => (int) $metrics['Pendientes']->value,
        'kpi_attending' => (int) $metrics['En atención']->value,
    ];
}

test('the department load of the dashboard export follows the connection and agent filters of the figures above it', function () {
    Conversation::factory()->count(2)->for($this->primary, 'channelConnection')->inDepartment($this->sales)->create();
    Conversation::factory()->count(3)->for($this->secondary, 'channelConnection')->inDepartment($this->sales)->create();
    Conversation::factory()->for($this->primary, 'channelConnection')->inDepartment($this->sales)->assignedTo($this->ana)->create();
    Conversation::factory()->count(2)->for($this->primary, 'channelConnection')->inDepartment($this->sales)->assignedTo($this->beto)->create();
    $admin = exportingAdministrator();

    expect(departmentLoadTotals($admin, ['connection' => $this->primary->id]))
        ->toBe(['table_pending' => 2, 'table_attending' => 3, 'kpi_pending' => 2, 'kpi_attending' => 3])
        ->and(departmentLoadTotals($admin, ['agent' => $this->ana->id]))
        ->toBe(['table_pending' => 0, 'table_attending' => 1, 'kpi_pending' => 0, 'kpi_attending' => 1]);
});

test('the first response chart of the exports keeps every bucket and leaves a gap where nothing was measured', function (string $report, string $title) {
    $factory = Conversation::factory()->for($this->primary, 'channelConnection')->inDepartment($this->sales)->assignedTo($this->ana);
    $factory->create(['created_at' => now()->subDays(5), 'updated_at' => now()->subDays(5)]);
    $factory->create(['created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
    app(RebuildConversationMetrics::class)->handle();
    ConversationMetric::query()->update(['seconds_to_first_human_response' => 120]);
    $admin = exportingAdministrator();
    $definition = app(ReportRegistry::class)->find($report);
    $context = app(ReportExporter::class)->prepare($definition, $admin, ['period' => '7d']);

    $chart = collect($definition->charts($context))->firstWhere('title', $title);

    expect($chart->labels)->toHaveCount(7)
        ->and($chart->series[0]->values)->toBe([null, 2.0, null, null, null, 2.0, null]);
})->with([
    'dashboard' => ['dashboard', 'Tiempo de primera respuesta'],
    'reports' => ['analytics', 'Tiempo de primera respuesta'],
]);

test('the PDF line breaks at a gap instead of joining the measured points', function () {
    $chart = new Chart(ChartType::Line, 'Tiempo', ['1', '2', '3', '4', '5'], [new ChartSeries('Promedio', [1.0, 2.0, null, null, 4.0])]);

    $series = app(ChartGeometry::class)->line($chart)['series'][0];

    expect($series['segments'])->toHaveCount(1)
        ->and(explode(' ', $series['segments'][0]))->toHaveCount(2)
        ->and($series['dots'])->toHaveCount(3)
        ->and($series['last']['value'])->toBe('4');
});

test('the overview says when the role does not include conversations, apart from a channel without traffic', function () {
    Conversation::factory()->for($this->primary, 'channelConnection')->inDepartment($this->sales)->create();
    $reporter = User::factory()
        ->withRole(Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewDashboard, Permission::ViewReports])->create())
        ->create();

    $this->actingAs($reporter)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('default', fn (Assert $reload) => $reload
            ->where('overview.messaging_connected', true)
            ->where('overview.conversations_visible', false)
            ->where('overview.conversations.pending', null)));

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('default', fn (Assert $reload) => $reload
            ->where('overview.conversations_visible', true)
            ->where('overview.conversations.pending', 1)));
});

test('the finished today figure of an export uses the day it was requested on, like its department table', function () {
    Conversation::factory()->for($this->primary, 'channelConnection')->inDepartment($this->sales)->assignedTo($this->ana)->closed()
        ->create(['closed_at' => CarbonImmutable::parse('2026-10-20 20:00', 'America/Mexico_City')->utc()]);
    $admin = exportingAdministrator();
    $definition = app(ReportRegistry::class)->find('dashboard');
    $context = new ReportContext(
        $admin,
        [],
        now()->toImmutable(),
        'Chat Manager',
        'America/Mexico_City',
        CarbonImmutable::parse('2026-10-20 23:59', 'America/Mexico_City')->utc(),
    );

    $metric = collect($definition->metrics($context))->firstWhere('label', 'Finalizadas hoy');
    $table = collect(iterator_to_array($definition->rows($context), false))->sum('closed_today');

    expect($metric->value)->toBe(1)->and($table)->toBe(1);
});

test('the dashboard computes the activity buckets once for the overview and the charts', function () {
    Conversation::factory()->for($this->primary, 'channelConnection')->inDepartment($this->sales)->create(['created_at' => now()->subDay()]);
    app(RebuildConversationMetrics::class)->handle();
    $admin = User::factory()->administrator()->create();
    $metrics = app(DashboardMetrics::class);
    $buckets = 0;
    DB::listen(function ($query) use (&$buckets) {
        if (str_contains($query->sql, 'DATE_FORMAT(')) {
            $buckets++;
        }
    });

    $overview = $metrics->overviewFor($admin);
    $charts = $metrics->chartsFor($admin);

    expect($buckets)->toBe(1)
        ->and($overview['trend'])->toBe(array_map(fn (array $point): array => ['label' => $point['label'], 'received' => $point['received']], $charts['activity']['points']));
});

test('a global administrator never shares cached figures with the root account', function () {
    $root = User::factory()->superAdministrator()->create();
    // Same permissions on both sides: only the root authority tells them apart.
    $root->roles->first()->revokePermissionTo(Permission::ImportContacts->value);
    $root->refresh();
    $twin = User::factory()
        ->withRole(Role::factory()->scope(DataScope::Global)->withPermissions(array_map(
            fn (string $permission): Permission => Permission::from($permission),
            $root->effectivePermissions(),
        ))->create())
        ->create();
    $scope = app(AnalyticsScope::class);

    expect($twin->effectivePermissions())->toEqualCanonicalizing($root->effectivePermissions())
        ->and($scope->fingerprint($twin))->not->toBe($scope->fingerprint($root));
});

test('the reports export maps each hidden root agent to its own figures', function () {
    $first = User::factory()->superAdministrator()->create(['name' => 'Raíz Uno']);
    $second = User::factory()->superAdministrator()->create(['name' => 'Raíz Dos']);
    $factory = Conversation::factory()->for($this->primary, 'channelConnection')->inDepartment($this->sales);
    $factory->assignedTo($first)->count(2)->create(['created_at' => now()->subDay()]);
    $factory->assignedTo($second)->create(['created_at' => now()->subDay()]);
    app(RebuildConversationMetrics::class)->handle();
    $admin = exportingAdministrator();
    $definition = app(ReportRegistry::class)->find('analytics');
    $context = app(ReportExporter::class)->prepare($definition, $admin, ['period' => '7d']);

    $rows = collect(iterator_to_array($definition->rows($context), false));

    expect($rows->pluck('name')->all())->toBe(['Administración del sistema', 'Administración del sistema'])
        ->and($rows->pluck('received')->all())->toBe([2, 1]);
});
