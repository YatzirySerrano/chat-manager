<?php

use App\Actions\Analytics\RebuildConversationMetrics;
use App\Enums\DataScope;
use App\Enums\DataTransferStatus;
use App\Enums\ExportFormat;
use App\Enums\Permission;
use App\Jobs\GenerateReport;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\ConversationMetric;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Reports\Rendering\PdfRenderer;
use App\Reports\ReportRegistry;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\AnalyticsScope;
use App\Services\Analytics\OperationalAnalytics;
use App\Services\Exports\ReportExporter;
use App\Services\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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

    $this->actingAs(conversationUser([Permission::ViewDashboard, Permission::ViewConversations, Permission::ReplyConversations]))->get(route('reports.index'))->assertForbidden();
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
    $this->actingAs(reportsUser([Permission::ViewReports], DataScope::Global))->post(route('exports.generate', ['analytics', 'xlsx']))->assertForbidden();
    $this->actingAs(reportsUser([Permission::ExportReports, Permission::ViewDashboard], DataScope::Global))->post(route('exports.generate', ['analytics', 'pdf']))->assertForbidden();
    $this->actingAs(reportsUser([Permission::ViewReports, Permission::ExportReports], DataScope::Global))->post(route('exports.generate', ['analytics', 'xlsx']))->assertOk();
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
        ->post(route('exports.generate', ['analytics', 'pdf', 'period' => '7d']))
        ->assertOk();
    $path = $response->baseResponse->getFile()->getPathname();

    expect(file_get_contents($path, length: 4))->toBe('%PDF');
    @unlink($path);
})->skip(
    fn () => ! is_executable((string) config('exports.pdf.node_binary')) || ! is_executable((string) config('exports.pdf.chrome_path')),
    'Node or Chrome are not configured for this environment (EXPORTS_NODE_BINARY, EXPORTS_CHROME_PATH).',
)->group('chrome');

test('compliance is only rated against a configured objective, in business time', function () {
    $receivedAt = CarbonImmutable::parse('2026-10-19 10:00', 'America/Mexico_City')->utc();
    $fast = receivedConversation($this->sales, $this->ana, $receivedAt);
    $slow = receivedConversation($this->sales, $this->ana, $receivedAt);
    receivedConversation($this->sales, $this->ana, $receivedAt);
    app(RebuildConversationMetrics::class)->handle();
    ConversationMetric::query()->where('conversation_id', $fast->id)->update(['seconds_to_first_human_response' => 600, 'business_seconds_to_first_human_response' => 600]);
    ConversationMetric::query()->where('conversation_id', $slow->id)->update(['seconds_to_first_human_response' => 90000, 'business_seconds_to_first_human_response' => 1800]);
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)
        ->get(route('reports.index', ['period' => '7d']))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('analytics.summary.first_response_business_seconds', ['value' => 1200, 'previous' => null, 'samples' => 2])
            ->where('analytics.summary.first_response_sla', null)
            ->where('analytics.summary.resolution_sla', null)));

    app(PlatformSettings::class)->update(['sla' => ['first_response_minutes' => 15]]);

    $this->actingAs($admin)
        ->get(route('reports.index', ['period' => '7d']))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
            // The unanswered conversation is not rated; the slow one missed the 15 min objective.
            ->where('analytics.summary.first_response_sla', ['target_seconds' => 900, 'met' => 1, 'samples' => 2, 'rate' => 50, 'previous_rate' => null])
            ->where('analytics.summary.resolution_sla', null)));
});

test('days and hours are grouped with the offset in force on each date', function () {
    app(PlatformSettings::class)->update(['general' => ['timezone' => 'America/New_York']]);
    // Daylight saving ends on 2026-11-01: 23:30 on 3 November is 04:30 UTC of the 4th.
    receivedConversation($this->sales, $this->ana, CarbonImmutable::parse('2026-11-03 23:30', 'America/New_York')->utc());
    receivedConversation($this->sales, $this->ana, CarbonImmutable::parse('2026-11-03 10:15', 'America/New_York')->utc());
    app(RebuildConversationMetrics::class)->handle();

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('reports.index', ['period' => 'custom', 'from' => '2026-10-20', 'to' => '2026-11-10']))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
            // Tuesday (1) at 10:00 and 23:00 local time.
            ->where('analytics.heatmap.cells.1.10', 1)
            ->where('analytics.heatmap.cells.1.23', 1)
            ->where('analytics.trend.points', fn ($points) => collect($points)->firstWhere('key', '2026-11-03')['received'] === 2
                && collect($points)->firstWhere('key', '2026-11-04')['received'] === 0)));
});

test('the exported agent table follows the screen order and its own snapshot', function () {
    $now = CarbonImmutable::now();
    $beto = User::factory()->agent()->inDepartments($this->sales)->create(['name' => 'Beto Ventas']);
    receivedConversation($this->sales, $this->ana, $now->subDay());
    receivedConversation($this->sales, $beto, $now->subDay());
    receivedConversation($this->sales, $beto, $now->subDay());
    app(RebuildConversationMetrics::class)->handle();
    $admin = User::factory()->administrator()->create();
    $exporter = app(ReportExporter::class);
    $definition = app(ReportRegistry::class)->find('analytics');
    $context = $exporter->prepare($definition, $admin, ['period' => '7d']);

    // The screen caches its figures; a new agent arrives afterwards.
    $screen = app(OperationalAnalytics::class)->report($admin, AnalyticsFilters::from(['period' => '7d'], $admin, app(AnalyticsScope::class), 'America/Mexico_City'));
    receivedConversation($this->sales, $this->carla, $now->subDay());
    app(RebuildConversationMetrics::class)->handle();

    $path = $exporter->temporaryPath(ExportFormat::Xlsx);
    $written = $exporter->render($definition, $context, ExportFormat::Xlsx, $path);
    $names = collect(IOFactory::createReader('Xlsx')->load($path)->getSheetByName('Reporte operativo')->toArray())
        ->slice(6, $written)->pluck(0)->all();
    @unlink($path);

    expect(array_column($screen['by_agent'], 'name'))->toBe(['Beto Ventas', 'Ana Ventas'])
        ->and($written)->toBe($definition->count($context))
        ->and($names)->toBe(['Beto Ventas', 'Ana Ventas', 'Carla Soporte'])
        ->and($names)->not->toContain('Sin datos');
});

test('the PDF keeps the totals with the last rows and totals every section like the workbook', function () {
    $now = CarbonImmutable::now();
    receivedConversation($this->sales, $this->ana, $now->subDay());
    receivedConversation($this->sales, $this->ana, $now->subDay());
    receivedConversation($this->support, $this->carla, $now->subDay());
    app(RebuildConversationMetrics::class)->handle();
    $exporter = app(ReportExporter::class);
    $definition = app(ReportRegistry::class)->find('analytics');
    $context = $exporter->prepare($definition, User::factory()->administrator()->create(), ['period' => '7d']);

    $html = app(PdfRenderer::class)->html($definition, $context);
    $tails = preg_match_all('#<tbody class="table-end">(.*?)</tbody>#s', $html, $matches);

    expect($html)->not->toContain('<tfoot')
        // Main table, "Por departamento" and "Por conexión".
        ->and($tails)->toBe(3);

    foreach ($matches[1] as $tail) {
        expect($tail)->toContain('<tr class="row')
            ->toContain('<tr class="totals">');
    }

    // The connection section sums every received conversation.
    expect(preg_replace('/\s+/', ' ', strip_tags($matches[1][2])))->toMatch('/Total 3 /');
});

test('a queued export resolves a relative period at the moment it was requested', function () {
    Queue::fake();
    Storage::fake('local');
    config(['exports.formats.xlsx.sync_rows' => 0]);
    $this->travelTo(CarbonImmutable::parse('2026-10-21 23:59', 'America/Mexico_City')->utc());
    receivedConversation($this->sales, $this->ana, CarbonImmutable::now()->subHour());
    app(RebuildConversationMetrics::class)->handle();
    $exporter = app(ReportExporter::class);
    $definition = app(ReportRegistry::class)->find('analytics');
    $context = $exporter->prepare($definition, reportsUser([Permission::ViewReports, Permission::ExportReports], DataScope::Global), ['period' => 'today']);

    $transfer = $exporter->export($definition, $context, ExportFormat::Xlsx);

    // The worker picks it up two minutes later, already on the next day.
    $this->travelTo(CarbonImmutable::parse('2026-10-22 00:01', 'America/Mexico_City')->utc());
    // Queued (and holding its unique lock): the worker runs the job itself.
    Queue::assertPushed(GenerateReport::class);
    app()->call([new GenerateReport($transfer->id), 'handle']);

    $transfer->refresh();
    $stored = Storage::disk('local')->path($transfer->path);
    $text = collect(IOFactory::createReader('Xlsx')->load($stored)->getAllSheets())
        ->flatMap(fn ($sheet) => collect($sheet->toArray())->flatten())
        ->implode('|');

    expect($transfer->status)->toBe(DataTransferStatus::Completed)
        ->and($transfer->rows_total)->toBe(1)
        ->and($text)->toContain('Periodo: 21 oct 2026')
        ->and($text)->toContain('Ana Ventas')
        ->and($text)->not->toContain('Periodo: 22 oct 2026');
});
