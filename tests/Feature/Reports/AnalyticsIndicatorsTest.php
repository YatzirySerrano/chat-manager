<?php

use App\Actions\Analytics\RebuildConversationMetrics;
use App\Enums\ConversationOrigin;
use App\Enums\DataScope;
use App\Enums\ExportFormat;
use App\Enums\Permission;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\ConversationCategory;
use App\Models\ConversationMetric;
use App\Models\Department;
use App\Models\ResponseCycle;
use App\Models\Role;
use App\Models\User;
use App\Reports\ReportRegistry;
use App\Services\Analytics\AgentProductivity;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\AnalyticsScope;
use App\Services\Analytics\OperationalAnalytics;
use App\Services\DashboardMetrics;
use App\Services\Exports\ReportExporter;
use App\Services\PlatformSettings;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/*
| Indicator definitions shared by the dashboard, the reports and the exports:
| weeks, like-with-like comparison, extremes, backlog, filters and reach.
*/

beforeEach(function () {
    // Wednesday 2026-10-21 12:00 local time (18:00 UTC).
    $this->now = CarbonImmutable::parse('2026-10-21 12:00', 'America/Mexico_City');
    $this->travelTo($this->now->utc());
    $this->sales = Department::factory()->create(['name' => 'Ventas']);
    $this->support = Department::factory()->create(['name' => 'Soporte']);
    $this->connection = Connection::factory()->operational()->create(['name' => 'Línea principal']);
    $this->ana = User::factory()->agent()->inDepartments($this->sales)->create(['name' => 'Ana Ventas']);
    $this->carla = User::factory()->agent()->inDepartments($this->support)->create(['name' => 'Carla Soporte']);
    $this->admin = User::factory()->administrator()->create();
});

/**
 * A conversation received at a local moment; its metrics row gets the given
 * columns once the rows are rebuilt (indicatorRebuild()).
 *
 * @param  array<string, mixed>  $attributes
 */
function indicatorConversation(CarbonImmutable $receivedAt, ?Department $department = null, ?User $agent = null, array $attributes = []): Conversation
{
    $factory = Conversation::factory()->inDepartment($department ?? test()->sales);
    $factory = $agent === null ? $factory : $factory->assignedTo($agent);

    return $factory->create(['connection_id' => test()->connection->id, 'created_at' => $receivedAt->utc(), 'updated_at' => $receivedAt->utc(), ...$attributes]);
}

/**
 * Rebuild the derived rows and overwrite columns of some of them.
 *
 * @param  array<int, array<string, mixed>>  $overrides  Conversation id => metric columns.
 */
function indicatorRebuild(array $overrides = []): void
{
    app(RebuildConversationMetrics::class)->handle();

    foreach ($overrides as $conversationId => $columns) {
        ConversationMetric::query()->where('conversation_id', $conversationId)->update($columns);
    }
}

function indicatorFilters(User $viewer, array $input, ?string $timezone = null): AnalyticsFilters
{
    return AnalyticsFilters::from($input, $viewer, app(AnalyticsScope::class), $timezone ?? 'America/Mexico_City');
}

/**
 * A measured response cycle of a conversation: met, or breached under its assignee.
 */
function indicatorCycle(Conversation $conversation, bool $met): ResponseCycle
{
    $startedAt = CarbonImmutable::parse($conversation->created_at);

    return ResponseCycle::factory()->create([
        'conversation_id' => $conversation->id,
        'started_at' => $startedAt,
        'target_seconds' => 300,
        'business_hours_only' => false,
        'due_at' => $startedAt->addSeconds(300),
        'responded_at' => $startedAt->addSeconds($met ? 60 : 900),
        'ended_at' => $startedAt->addSeconds($met ? 60 : 900),
        'responder_user_id' => $conversation->assigned_user_id,
        'outcome' => ResponseCycle::OUTCOME_ANSWERED,
        'response_seconds' => $met ? 60 : 900,
        'breached_at' => $met ? null : $startedAt->addSeconds(300),
        'breach_user_id' => $met ? null : $conversation->assigned_user_id,
        'exceeded_seconds' => $met ? null : 600,
        'active' => null,
    ]);
}

/**
 * First row of a sheet whose first cell holds the text.
 */
function indicatorSheetRow(Worksheet $sheet, string $firstCell): ?int
{
    foreach ($sheet->getRowIterator() as $row) {
        if ((string) $sheet->getCell("A{$row->getRowIndex()}")->getValue() === $firstCell) {
            return $row->getRowIndex();
        }
    }

    return null;
}

test('weeks are ISO weeks of the platform time zone, also across a daylight saving change', function () {
    app(PlatformSettings::class)->update(['general' => ['timezone' => 'America/New_York']]);
    $zone = 'America/New_York';
    $this->travelTo(CarbonImmutable::parse('2026-11-04 12:00', $zone)->utc());
    // Sunday night (week 43), the Sunday the clocks go back (week 44) and the first minutes of Monday (week 45).
    indicatorConversation(CarbonImmutable::parse('2026-10-25 23:30', $zone));
    indicatorConversation(CarbonImmutable::parse('2026-11-01 23:30', $zone));
    indicatorConversation(CarbonImmutable::parse('2026-11-02 00:30', $zone));
    indicatorRebuild();

    $filters = indicatorFilters($this->admin, ['period' => 'custom', 'from' => '2026-10-19', 'to' => '2026-11-08', 'granularity' => 'week'], $zone);
    $trend = app(OperationalAnalytics::class)->trend($this->admin, $filters);

    expect($trend['granularity'])->toBe('week')
        ->and(array_column($trend['points'], 'received', 'key'))->toBe(['2026-W43' => 1, '2026-W44' => 1, '2026-W45' => 1])
        ->and($trend['points'][0]['label'])->toBe('sem 19 oct')
        ->and($filters->period->toArray()['granularities'])->toBe(['day', 'week', 'month'])
        ->and($filters->toQuery())->toMatchArray(['granularity' => 'week']);
});

test('a period in progress is compared with the same elapsed portion of the previous one', function () {
    indicatorConversation($this->now->setTime(9, 0));
    indicatorConversation($this->now->subDay()->setTime(9, 0));
    // Yesterday afternoon: today has not got that far yet.
    indicatorConversation($this->now->subDay()->setTime(15, 0));
    indicatorConversation($this->now->subDays(2)->setTime(15, 0));
    indicatorRebuild();
    $analytics = app(OperationalAnalytics::class);

    $today = indicatorFilters($this->admin, ['period' => 'today']);
    $yesterday = indicatorFilters($this->admin, ['period' => 'yesterday']);

    expect($analytics->summary($this->admin, $today)['received'])->toBe(['value' => 1, 'previous' => 1])
        ->and($today->period->toArray()['comparison'])->toMatchArray(['partial' => true, 'until' => '12:00'])
        // A finished period is compared with the whole previous one.
        ->and($analytics->summary($this->admin, $yesterday)['received'])->toBe(['value' => 2, 'previous' => 1])
        ->and($yesterday->period->toArray()['comparison']['partial'])->toBeFalse();
});

test('averages only use the rows that have the datum, with their extremes and sample size', function () {
    $fast = indicatorConversation($this->now->subDay());
    $slow = indicatorConversation($this->now->subDay());
    indicatorConversation($this->now->subDay());
    indicatorRebuild([
        $fast->id => ['seconds_to_first_human_response' => 60],
        $slow->id => ['seconds_to_first_human_response' => 300],
    ]);

    $summary = app(OperationalAnalytics::class)->summary($this->admin, indicatorFilters($this->admin, ['period' => '7d']));

    expect($summary['first_response_seconds'])->toBe(['value' => 180, 'previous' => null, 'samples' => 2])
        ->and($summary['first_response_range'])->toBe(['min' => 60, 'max' => 300])
        // Nothing resolved: no average, no extremes (never zeros).
        ->and($summary['resolution_seconds'])->toBe(['value' => null, 'previous' => null, 'samples' => 0])
        ->and($summary['resolution_range'])->toBe(['min' => null, 'max' => null]);
});

test('finished of the received and finished in the period are two separate figures, and a conversation counts once', function () {
    // Received 40 days ago, reassigned and with many messages, closed yesterday for the first time.
    $old = indicatorConversation($this->now->subDays(40), $this->sales, $this->ana);
    $recent = indicatorConversation($this->now->subDays(2));
    indicatorRebuild([
        $old->id => ['resolved_at' => $this->now->subDay()->utc(), 'messages_in' => 12, 'messages_out' => 9, 'transfers_count' => 3, 'reopened_count' => 1, 'closed_count' => 2],
    ]);
    $analytics = app(OperationalAnalytics::class);
    $filters = indicatorFilters($this->admin, ['period' => '30d']);

    $summary = $analytics->summary($this->admin, $filters);

    expect($summary['received']['value'])->toBe(1)
        ->and($summary['resolved']['value'])->toBe(0)
        ->and($summary['closed_in_period']['value'])->toBe(1)
        ->and(array_sum(array_column($analytics->trend($this->admin, $filters)['points'], 'resolved')))->toBe(1)
        // In the period it arrived in, it is one received conversation, already finished.
        ->and($analytics->summary($this->admin, indicatorFilters($this->admin, ['period' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30'])))
        ->received->toBe(['value' => 1, 'previous' => 0])
        ->resolved->toBe(['value' => 1, 'previous' => 0])
        ->closed_in_period->toBe(['value' => 0, 'previous' => 0])
        ->and($recent->exists)->toBeTrue();
});

test('the backlog accumulates what arrived and was still unattended at the end of each day', function () {
    $day = fn (int $dayOfMonth, int $hour = 10): CarbonImmutable => CarbonImmutable::parse("2026-10-{$dayOfMonth} {$hour}:00", 'America/Mexico_City');
    $waiting = indicatorConversation($day(10));
    $leftInPeriod = indicatorConversation($day(12));
    $sameDay = indicatorConversation($day(17));
    $new = indicatorConversation($day(19));
    $longGone = indicatorConversation($day(1));
    indicatorRebuild([
        $leftInPeriod->id => ['left_backlog_at' => $day(16)->utc()],
        $sameDay->id => ['left_backlog_at' => $day(17, 11)->utc()],
        $longGone->id => ['left_backlog_at' => $day(5)->utc()],
    ]);

    $backlog = app(OperationalAnalytics::class)->backlog($this->admin, indicatorFilters($this->admin, ['period' => '7d']));

    expect($backlog['start'])->toBe(2)
        ->and($backlog['now'])->toBe(2)
        ->and($backlog['end'])->toBe(2)
        ->and(array_column($backlog['points'], 'backlog', 'key'))->toBe([
            '2026-10-15' => 2,
            '2026-10-16' => 1,
            '2026-10-17' => 1,
            '2026-10-18' => 1,
            '2026-10-19' => 2,
            '2026-10-20' => 2,
            // Today is not over: no figure yet.
            '2026-10-21' => null,
        ])
        ->and($waiting->exists && $new->exists)->toBeTrue();
});

test('category, status and channel filter the figures; values outside the options are dropped', function () {
    $billing = ConversationCategory::factory()->create(['name' => 'Facturación']);
    $webChat = Connection::factory()->webChat()->create(['name' => 'Chat del sitio']);
    indicatorConversation($this->now->subDay(), attributes: ['category_id' => $billing->id]);
    indicatorConversation($this->now->subDay(), attributes: ['category_id' => $billing->id, 'status' => 'closed', 'closed_at' => now()]);
    indicatorConversation($this->now->subDay(), attributes: ['connection_id' => $webChat->id]);
    indicatorRebuild();
    $analytics = app(OperationalAnalytics::class);
    $received = fn (array $input): int => $analytics->summary($this->admin, indicatorFilters($this->admin, ['period' => '7d', ...$input]))['received']['value'];

    expect($received([]))->toBe(3)
        ->and($received(['category' => $billing->id]))->toBe(2)
        ->and($received(['category' => $billing->id, 'status' => 'closed']))->toBe(1)
        ->and($received(['channel' => 'web_chat']))->toBe(1)
        ->and($received(['status' => 'pending']))->toBe(2)
        ->and(collect($analytics->breakdown($this->admin, indicatorFilters($this->admin, ['period' => '7d']), 'category_id'))->pluck('received', 'name')->all())
        ->toBe(['Facturación' => 2, 'Sin categoría' => 1])
        // An unknown category is neither applied nor echoed back.
        ->and(indicatorFilters($this->admin, ['period' => '7d', 'category' => 999999])->toQuery())->toBe(['period' => '7d']);

    $this->actingAs($this->admin)
        ->get(route('reports.index', ['status' => 'on_hold']))
        ->assertInertia(fn (Assert $page) => $page->where('invalidFilters', fn (?string $message) => $message !== null)->where('filters', []));
});

test('live figures leave out imported history and ignore the status filter', function () {
    indicatorConversation($this->now->subDay(), $this->sales, $this->ana, ['status' => 'open']);
    indicatorConversation($this->now->subDay());
    indicatorConversation($this->now->subDay(), attributes: ['origin' => ConversationOrigin::Whaticket]);
    indicatorRebuild();
    $analytics = app(OperationalAnalytics::class);

    $all = $analytics->compute($this->admin, indicatorFilters($this->admin, ['period' => '7d']));
    $closedOnly = $analytics->compute($this->admin, indicatorFilters($this->admin, ['period' => '7d', 'status' => 'closed']));

    expect($all['current'])->toMatchArray(['pending' => 1, 'open' => 1])
        ->and(collect($all['by_agent'])->firstWhere('name', 'Ana Ventas')['current_load'])->toBe(1)
        ->and($all['productivity']['workload'])->toMatchArray(['unassigned' => 1])
        // The period figures follow the status filter; the live ones cannot.
        ->and($closedOnly['summary']['received']['value'])->toBe(0)
        ->and($closedOnly['current'])->toMatchArray(['pending' => 1, 'open' => 1]);
});

test('the dashboard and the reports count active agents with one definition', function () {
    $lead = User::factory()->supervisor()->inDepartments($this->sales)->create();

    $reports = app(OperationalAnalytics::class)->snapshot($lead, indicatorFilters($lead, ['period' => '7d']))['agents'];
    $dashboard = app(DashboardMetrics::class)->overviewFor($lead)['agents']['active'];

    // The supervisor and Ana; Carla works in another department.
    expect($reports)->toBe(2)->and($dashboard)->toBe(2);
});

test('the dashboard, the reports and the exports give the same figures for the same filters', function () {
    $billing = ConversationCategory::factory()->create(['name' => 'Facturación']);
    $met = indicatorConversation($this->now->subDay(), $this->sales, $this->ana, ['category_id' => $billing->id]);
    $late = indicatorConversation($this->now->subDay(), $this->support, $this->carla, ['category_id' => $billing->id]);
    indicatorConversation($this->now->subDay(), $this->sales, $this->ana);
    indicatorCycle($met, true);
    indicatorCycle($late, false);
    indicatorRebuild();
    $input = ['period' => '7d', 'category' => $billing->id];
    $filters = indicatorFilters($this->admin, $input);
    $exporter = app(ReportExporter::class);
    $registry = app(ReportRegistry::class);
    $metric = function (string $report, string $label) use ($exporter, $registry, $input): mixed {
        $definition = $registry->find($report);

        return collect($definition->metrics($exporter->prepare($definition, $this->admin, $input)))->firstWhere('label', $label)?->value;
    };

    $screen = app(OperationalAnalytics::class)->compute($this->admin, $filters);
    $dashboard = app(DashboardMetrics::class);
    $overview = $dashboard->overviewFor($this->admin, $filters);
    $charts = $dashboard->chartsFor($this->admin, $filters);

    expect($screen['summary']['received']['value'])->toBe(2)
        ->and($overview['received']['value'])->toBe(2)
        ->and($metric('analytics', 'Recibidas'))->toBe(2)
        ->and($metric('dashboard', 'Recibidas en el periodo'))->toBe(2)
        // Service level: one met, one breached, in every place.
        ->and($screen['sla']['totals'])->toMatchArray(['met' => 1, 'breached' => 1, 'samples' => 2, 'rate' => 50.0])
        ->and($charts['sla']['totals'])->toBe($screen['sla']['totals'])
        ->and($metric('analytics', 'Cumplimiento de SLA'))->toBe('50 %')
        ->and($metric('dashboard', 'Cumplimiento de SLA'))->toBe('50 %')
        ->and($metric('sla-compliance', 'Cumplimiento de SLA'))->toBe('50 %')
        ->and($metric('sla-compliance', 'Fuera del plazo'))->toBe(1);

    // Productivity: the exported rows are the rows of the screen.
    $definition = $registry->find('agent-productivity');
    $context = $exporter->prepare($definition, $this->admin, $input);
    $exported = iterator_to_array($definition->rows($context), false);
    $rows = app(AgentProductivity::class)->rows($this->admin, $filters);

    expect(array_column($exported, 'name'))->toBe(array_column($rows, 'name'))
        ->and(array_column($exported, 'sla_met'))->toBe(array_column($rows, 'sla_met'))
        ->and(array_column($exported, 'pending_now'))->toBe(array_column($screen['productivity']['rows'], 'pending_now'))
        ->and($definition->count($context))->toBe(count($rows));
});

test('a personal reach receives nothing about colleagues through the page props', function () {
    $mine = indicatorConversation($this->now->subDay(), $this->sales, $this->ana);
    $theirs = indicatorConversation($this->now->subDay(), $this->support, $this->carla);
    indicatorCycle($mine, true);
    indicatorCycle($theirs, false);
    indicatorRebuild();
    $role = Role::factory()->scope(DataScope::Assigned)->withPermissions([Permission::ViewReports, Permission::ViewDashboard, Permission::ViewConversations, Permission::ReplyConversations])->create();
    $ana = tap($this->ana)->syncRoles([$role])->refresh();

    $this->actingAs($ana)
        ->get(route('reports.index', ['period' => '7d', 'agent' => $this->carla->id, 'department' => $this->support->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters', ['period' => '7d'])
            ->where('options.agents', [])
            ->where('options.departments', [])
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('analytics.summary.received.value', 1)
                ->where('analytics.by_department', [])
                ->where('analytics.productivity.rows', fn ($rows) => collect($rows)->pluck('name')->all() === ['Ana Ventas'])
                ->where('analytics.productivity.workload.unassigned', 0)
                ->where('analytics.productivity.evolution.agents', fn ($agents) => collect($agents)->pluck('name')->all() === ['Ana Ventas'])
                ->where('analytics.sla.totals.cycles', 1)
                ->where('analytics.sla.totals.breached', 0)
                ->where('analytics.sla.by_department', fn ($rows) => collect($rows)->pluck('name')->all() === ['Ventas'])));

    $this->actingAs($ana)
        ->get(route('dashboard', ['period' => '7d']))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('charts', fn (Assert $reload) => $reload
            ->where('charts.agents', null)
            ->where('charts.departments', null)
            ->where('charts.sla.totals.cycles', 1)
            ->where('charts.workload.rows', fn ($rows) => collect($rows)->pluck('name')->all() === ['Ana Ventas'])
            ->where('charts.workload.unassigned', 0)));
});

test('the new exports require reports.view and reports.export', function (string $report) {
    $viewer = fn (array $permissions): User => User::factory()->withRole(Role::factory()->scope(DataScope::Global)->withPermissions($permissions)->create())->create();

    $this->actingAs($viewer([Permission::ViewReports]))->post(route('exports.generate', [$report, 'xlsx']))->assertForbidden();
    $this->actingAs($viewer([Permission::ExportReports, Permission::ViewDashboard]))->post(route('exports.generate', [$report, 'xlsx']))->assertForbidden();
    $this->actingAs($viewer([Permission::ViewReports, Permission::ExportReports]))->post(route('exports.generate', [$report, 'xlsx']))->assertOk();
})->with(['agent-productivity', 'sla-compliance']);

test('the productivity workbook opens with real numbers, percentages and its sheets', function () {
    $met = indicatorConversation($this->now->subDay(), $this->sales, $this->ana);
    indicatorCycle($met, true);
    indicatorRebuild();
    $exporter = app(ReportExporter::class);
    $definition = app(ReportRegistry::class)->find('agent-productivity');
    $path = $exporter->temporaryPath(ExportFormat::Xlsx);
    $exporter->render($definition, $exporter->prepare($definition, $this->admin, ['period' => '7d']), ExportFormat::Xlsx, $path);

    $workbook = IOFactory::createReader('Xlsx')->load($path);
    @unlink($path);
    $sheet = $workbook->getSheetByName('Productividad por colabor') ?? $workbook->getSheet(1);
    $row = indicatorSheetRow($sheet, 'Ana Ventas');
    $header = $row === null ? [] : array_map('strval', $sheet->rangeToArray('A'.($row - 1).':O'.($row - 1))[0]);

    expect($workbook->getSheetNames())->toContain('Resumen', 'Evolución')
        ->and($row)->not->toBeNull()
        ->and($header)->toContain('Colaborador', 'Atendidas', 'Finalizadas (cierres)', 'Tiempo de atención (min)', 'Cumplimiento SLA')
        // "Asignadas" is a number cell and the compliance a real percentage (1 = 100 %).
        ->and($sheet->getCell("B{$row}")->getDataType())->toBe(DataType::TYPE_NUMERIC)
        ->and($sheet->getCell("B{$row}")->getValue())->toEqual(1)
        ->and($sheet->getCell("O{$row}")->getValue())->toEqual(1)
        ->and($sheet->getStyle("O{$row}")->getNumberFormat()->getFormatCode())->toContain('%');
});

test('the service level workbook lists each breach with real dates and durations in minutes', function () {
    $late = indicatorConversation($this->now->subDay(), $this->support, $this->carla);
    $met = indicatorConversation($this->now->subDay(), $this->sales, $this->ana);
    indicatorCycle($late, false);
    indicatorCycle($met, true);
    indicatorRebuild();
    $exporter = app(ReportExporter::class);
    $definition = app(ReportRegistry::class)->find('sla-compliance');
    $context = $exporter->prepare($definition, $this->admin, ['period' => '7d']);
    $path = $exporter->temporaryPath(ExportFormat::Xlsx);
    $exporter->render($definition, $context, ExportFormat::Xlsx, $path);

    $workbook = IOFactory::createReader('Xlsx')->load($path);
    @unlink($path);
    $sheet = $workbook->getSheet(1);
    $row = indicatorSheetRow($sheet, (string) $late->id);

    expect($workbook->getSheetNames())->toContain('Resumen', 'Por tipo', 'Por departamento', 'Por categoría', 'Por colaborador', 'Tendencia')
        ->and($definition->count($context))->toBe(1)
        ->and($row)->not->toBeNull()
        ->and($sheet->getCell("C{$row}")->getValue())->toBe('Soporte')
        ->and($sheet->getCell("E{$row}")->getValue())->toBe('Carla Soporte')
        // The customer message is a real Excel date in the platform time zone.
        ->and(ExcelDate::isDateTime($sheet->getCell("F{$row}")))->toBeTrue()
        ->and(ExcelDate::excelToDateTimeObject($sheet->getCell("F{$row}")->getValue())->format('Y-m-d H:i'))->toBe('2026-10-20 12:00')
        // Objective 5 min, exceeded by 10 min: numbers with the unit in the header.
        ->and($sheet->getCell("I{$row}")->getValue())->toEqual(5)
        ->and($sheet->getCell("J{$row}")->getValue())->toEqual(10)
        ->and($sheet->getCell("K{$row}")->getValue())->toBe('Respondida tarde')
        ->and(indicatorSheetRow($sheet, (string) $met->id))->toBeNull();
});

test('real PDFs of the productivity and service level reports are printed with Chrome', function (string $report) {
    $late = indicatorConversation($this->now->subDay(), $this->support, $this->carla);
    indicatorCycle($late, false);
    indicatorRebuild();

    $response = $this->actingAs($this->admin)
        ->post(route('exports.generate', [$report, 'pdf', 'period' => '7d']))
        ->assertOk();
    $path = $response->baseResponse->getFile()->getPathname();

    expect(file_get_contents($path, length: 4))->toBe('%PDF');
    @unlink($path);
})->with(['agent-productivity', 'sla-compliance'])->skip(
    fn () => ! is_executable((string) config('exports.pdf.node_binary')) || ! is_executable((string) config('exports.pdf.chrome_path')),
    'Node or Chrome are not configured for this environment (EXPORTS_NODE_BINARY, EXPORTS_CHROME_PATH).',
)->group('chrome');
