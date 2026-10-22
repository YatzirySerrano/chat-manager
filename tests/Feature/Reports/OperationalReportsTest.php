<?php

use App\Actions\Analytics\RebuildConversationMetrics;
use App\Enums\DataScope;
use App\Enums\ExportFormat;
use App\Enums\Permission;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\ConversationMetric;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Reports\Rendering\PdfRenderer;
use App\Reports\ReportRegistry;
use App\Services\Exports\ReportExporter;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;

/*
| Reports module (/reports): figures over the per-conversation metrics,
| restricted to the viewer's reach, and its XLSX/PDF export.
*/

beforeEach(function () {
    // Wednesday 2026-10-21 12:00 local time (18:00 UTC).
    $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00', 'America/Mexico_City')->utc());
    $this->sales = Department::factory()->create(['name' => 'Ventas']);
    $this->support = Department::factory()->create(['name' => 'Soporte']);
    $this->connection = Connection::factory()->operational()->create(['name' => 'Línea principal']);
    $this->connection->departments()->attach([$this->sales->id, $this->support->id]);
    $this->ana = User::factory()->agent()->inDepartments($this->sales)->create(['name' => 'Ana Ventas']);
    $this->carla = User::factory()->agent()->inDepartments($this->support)->create(['name' => 'Carla Soporte']);
});

/**
 * A conversation received at the given time with its metrics computed.
 *
 * @param  array<string, mixed>  $attributes
 */
function receivedConversation(Department $department, ?User $agent, CarbonImmutable $receivedAt, array $attributes = []): Conversation
{
    $factory = Conversation::factory()->for(test()->connection, 'channelConnection')->inDepartment($department);
    $factory = $agent === null ? $factory : $factory->assignedTo($agent);

    return $factory->create(['created_at' => $receivedAt, 'updated_at' => $receivedAt, ...$attributes]);
}

function reportsUser(array $permissions, DataScope $scope, array $departments = []): User
{
    return User::factory()->withRole(Role::factory()->scope($scope)->withPermissions($permissions)->create())->inDepartments($departments)->create();
}

test('guests are redirected and users without reports.view are refused', function () {
    $this->get(route('reports.index'))->assertRedirect(route('login'));

    $this->actingAs(reportsUser([Permission::ViewDashboard, Permission::ViewConversations, Permission::ExportReports], DataScope::Global))
        ->get(route('reports.index'))
        ->assertForbidden();

    $this->actingAs(User::factory()->agent()->create())->get(route('reports.index'))->assertForbidden();
});

test('a global user sees every figure with the previous period comparison', function () {
    $now = CarbonImmutable::now();
    receivedConversation($this->sales, $this->ana, $now->subDays(2));
    receivedConversation($this->support, $this->carla, $now->subDays(3));
    receivedConversation($this->sales, null, $now->subDays(35));
    receivedConversation($this->sales, null, $now->subDays(400));
    app(RebuildConversationMetrics::class)->handle();

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('reports.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/Index')
            ->where('scope', 'all')
            ->where('period.preset', '30d')
            ->where('period.timezone', 'America/Mexico_City')
            ->missing('analytics')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('analytics.summary.received', ['value' => 2, 'previous' => 1])
                ->where('analytics.summary.attended.value', 0)
                ->where('analytics.summary.first_response_seconds', ['value' => null, 'previous' => null, 'samples' => 0])
                ->where('analytics.summary.out_of_hours.value', null)
                ->where('analytics.current.open', 2)
                ->where('analytics.current.pending', 2)
                ->has('analytics.by_agent', 2)
                ->has('analytics.by_department', 2)
                ->has('analytics.trend.points', 30)
                ->where('analytics.heatmap.max', 1)));
});

test('averages, rates and the heatmap are computed from the metrics', function () {
    $receivedAt = CarbonImmutable::parse('2026-10-19 10:15', 'America/Mexico_City')->utc();
    $first = receivedConversation($this->sales, $this->ana, $receivedAt);
    $second = receivedConversation($this->sales, $this->ana, $receivedAt);
    app(RebuildConversationMetrics::class)->handle();
    ConversationMetric::query()->where('conversation_id', $first->id)->update(['seconds_to_first_human_response' => 60, 'first_human_response_at' => $receivedAt->addMinute(), 'messages_out' => 3, 'failed_out' => 1]);
    ConversationMetric::query()->where('conversation_id', $second->id)->update(['seconds_to_first_human_response' => 180, 'first_human_response_at' => $receivedAt->addMinutes(3), 'messages_out' => 1]);

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('reports.index', ['period' => '7d']))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('analytics.summary.first_response_seconds.value', 120)
            ->where('analytics.summary.first_response_seconds.samples', 2)
            ->where('analytics.summary.responded.value', 2)
            ->where('analytics.summary.failure_rate.value', 25)
            // Monday (0) at 10:00 local time.
            ->where('analytics.heatmap.cells.0.10', 2)
            ->where('analytics.by_agent.0.name', 'Ana Ventas')
            ->where('analytics.by_agent.0.first_response_seconds', 120)
            ->where('analytics.by_agent.0.current_load', 2)));
});

test('a department scope only aggregates its departments and drops foreign filters', function () {
    $now = CarbonImmutable::now();
    receivedConversation($this->sales, $this->ana, $now->subDay());
    receivedConversation($this->support, $this->carla, $now->subDay());
    receivedConversation($this->support, $this->carla, $now->subDay());
    app(RebuildConversationMetrics::class)->handle();
    $supervisor = User::factory()->supervisor()->inDepartments($this->sales)->create();

    $this->actingAs($supervisor)
        ->get(route('reports.index', ['department' => $this->support->id, 'agent' => $this->carla->id, 'connection' => 999999]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('scope', 'departments')
            ->where('filters', [])
            ->where('options.departments', [['id' => $this->sales->id, 'name' => 'Ventas']])
            ->where('options.agents', fn ($agents) => collect($agents)->pluck('name')->doesntContain('Carla Soporte'))
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('analytics.summary.received.value', 1)
                ->where('analytics.by_agent', fn ($rows) => collect($rows)->pluck('name')->all() === ['Ana Ventas'])
                ->where('analytics.by_department', fn ($rows) => collect($rows)->pluck('name')->all() === ['Ventas'])));
});

test('an assigned scope only sees its own metrics, without department or agent filters', function () {
    $now = CarbonImmutable::now();
    receivedConversation($this->sales, $this->ana, $now->subDay());
    receivedConversation($this->sales, null, $now->subDay());
    $colleague = User::factory()->agent()->inDepartments($this->sales)->create();
    receivedConversation($this->sales, $colleague, $now->subDay());
    app(RebuildConversationMetrics::class)->handle();
    $agent = reportsUser([Permission::ViewReports, Permission::ViewConversations, Permission::ReplyConversations], DataScope::Assigned, [$this->sales]);
    receivedConversation($this->sales, $agent, $now->subDay());
    app(RebuildConversationMetrics::class)->handle();

    $this->actingAs($agent)
        ->get(route('reports.index', ['agent' => $colleague->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('scope', 'own')
            ->where('filters', [])
            ->where('options.departments', [])
            ->where('options.agents', [])
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('analytics.summary.received.value', 1)
                ->where('analytics.current.pending', 0)
                ->where('analytics.current.open', 1)
                ->where('analytics.current.available_agents', null)
                ->where('analytics.by_department', [])
                ->has('analytics.by_agent', 1)
                ->where('analytics.by_agent.0.id', $agent->id)));
});

test('filters persist in the query string and invalid ones fall back to the defaults', function () {
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)
        ->get(route('reports.index', ['period' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30', 'department' => $this->sales->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters', ['period' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30', 'department' => $this->sales->id])
            ->where('period.previous.from', '2026-08-02')
            ->where('period.previous.to', '2026-08-31')
            ->where('invalidFilters', null));

    $this->actingAs($admin)
        ->get(route('reports.index', ['period' => 'custom', 'from' => '2026-09-30', 'to' => '2026-09-01']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('period.preset', '30d')
            ->where('invalidFilters', fn ($message) => is_string($message)));
});

test('a long custom range is charted per month and capped', function () {
    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('reports.index', ['period' => 'custom', 'from' => '2024-01-01', 'to' => '2026-10-21']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('period.granularity', 'month')
            ->where('period.from', '2025-10-21'));
});

test('the analytics export requires reports.view and reports.export', function () {
    $this->actingAs(reportsUser([Permission::ViewReports], DataScope::Global))->get(route('exports.generate', ['analytics', 'xlsx']))->assertForbidden();
    $this->actingAs(reportsUser([Permission::ExportReports, Permission::ViewDashboard], DataScope::Global))->get(route('exports.generate', ['analytics', 'pdf']))->assertForbidden();
    $this->actingAs(reportsUser([Permission::ViewReports, Permission::ExportReports], DataScope::Global))->get(route('exports.generate', ['analytics', 'xlsx']))->assertOk();
});

test('the export contains the same figures as the screen, within the viewer reach', function () {
    $now = CarbonImmutable::now();
    receivedConversation($this->sales, $this->ana, $now->subDay());
    receivedConversation($this->support, $this->carla, $now->subDay());
    app(RebuildConversationMetrics::class)->handle();
    $supervisor = User::factory()->supervisor()->inDepartments($this->sales)->create();
    $supervisor->roles->first()->givePermissionTo(Permission::ExportReports->value);
    $supervisor->refresh();

    $exporter = app(ReportExporter::class);
    $definition = app(ReportRegistry::class)->find('analytics');
    $context = $exporter->prepare($definition, $supervisor, ['period' => '7d', 'department' => $this->support->id]);
    $path = $exporter->temporaryPath(ExportFormat::Xlsx);
    $exporter->render($definition, $context, ExportFormat::Xlsx, $path);

    $workbook = IOFactory::createReader('Xlsx')->load($path);
    $text = '';

    foreach ($workbook->getAllSheets() as $sheet) {
        foreach ($sheet->toArray() as $row) {
            $text .= implode('|', array_map('strval', $row))."\n";
        }
    }

    @unlink($path);

    expect($workbook->getSheetNames())->toContain('Resumen', 'Reporte operativo', 'Por departamento', 'Por conexión', 'Tendencia', 'Demanda por hora')
        ->and($text)->toContain('Ana Ventas')
        ->and($text)->not->toContain('Carla Soporte')
        ->and($text)->not->toContain('Soporte|');

    $html = app(PdfRenderer::class)->html($definition, $context);

    expect($html)->toContain('<h1>Reporte operativo</h1>')
        ->toContain('Desempeño por agente')
        ->not->toContain('Carla Soporte');
});

test('a real PDF of the operational report is printed with Chrome', function () {
    receivedConversation($this->sales, $this->ana, CarbonImmutable::now()->subDay());
    app(RebuildConversationMetrics::class)->handle();

    $response = $this->actingAs(reportsUser([Permission::ViewReports, Permission::ExportReports], DataScope::Global))
        ->get(route('exports.generate', ['analytics', 'pdf', 'period' => '7d']))
        ->assertOk();
    $path = $response->baseResponse->getFile()->getPathname();

    expect(file_get_contents($path, length: 4))->toBe('%PDF');
    @unlink($path);
})->skip(
    fn () => ! is_executable((string) config('exports.pdf.node_binary')) || ! is_executable((string) config('exports.pdf.chrome_path')),
    'Node or Chrome are not configured for this environment (EXPORTS_NODE_BINARY, EXPORTS_CHROME_PATH).',
)->group('chrome');
