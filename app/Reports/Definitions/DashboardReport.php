<?php

namespace App\Reports\Definitions;

use App\Enums\ConversationStatus;
use App\Enums\Permission;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use App\Reports\Chart;
use App\Reports\ChartSeries;
use App\Reports\ChartType;
use App\Reports\Column;
use App\Reports\ColumnTotal;
use App\Reports\ColumnType;
use App\Reports\Concerns\DescribesReports;
use App\Reports\Metric;
use App\Reports\PdfOptions;
use App\Reports\Rendering\ReportPalette;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportSheet;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\AnalyticsScope;
use App\Services\DashboardMetrics;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Executive summary of the dashboard: the same figures and charts the user
 * sees on the dashboard with the same filters (DashboardMetrics::overviewFor
 * and ::chartsFor, same reach, cache and "not available" rules), one data
 * sheet per chart, and a per-department live load computed only over the
 * conversations the user can see.
 *
 * Live figures (in attention, pending, finished today, department load)
 * count the conversations the user can see in the inbox; period figures and
 * charts follow the analytics reach (see DashboardMetrics).
 *
 * @phpstan-import-type Overview from DashboardMetrics
 * @phpstan-import-type Charts from DashboardMetrics
 *
 * @extends ReportDefinition<Department>
 */
class DashboardReport extends ReportDefinition
{
    use DescribesReports;

    /**
     * Overview computed once per export.
     *
     * @var array<int, Overview>
     */
    private array $overviews = [];

    /**
     * Charts computed once per export.
     *
     * @var array<int, Charts>
     */
    private array $charts = [];

    /**
     * Filters resolved once per export context.
     *
     * @var array<int, AnalyticsFilters>
     */
    private array $filters = [];

    public function __construct(
        private DashboardMetrics $dashboard,
        private AnalyticsScope $scope,
    ) {}

    public function key(): string
    {
        return 'dashboard';
    }

    public function title(): string
    {
        return 'Resumen ejecutivo';
    }

    public function subtitle(): ?string
    {
        return 'Indicadores del panel principal';
    }

    public function fileName(): string
    {
        return 'resumen-ejecutivo';
    }

    public function authorize(User $actor): bool
    {
        return $actor->holdsPermission(Permission::ViewDashboard);
    }

    public function rules(): array
    {
        return AnalyticsFilters::rules();
    }

    public function describeFilters(ReportContext $context): array
    {
        $filters = $this->filters($context);
        $descriptions = [
            'Incluye: '.match ($this->overview($context)['scope']) {
                'all' => 'toda la organización',
                'departments' => 'tus departamentos',
                default => 'tus conversaciones',
            },
            "Periodo de tiempos y gráficas: {$filters->period->label()} ({$filters->period->timezone})",
        ];

        if ($filters->departmentId !== null) {
            $descriptions[] = 'Departamento: '.Department::query()->whereKey($filters->departmentId)->value('name');
        }

        if ($filters->agentId !== null) {
            $descriptions[] = 'Agente: '.User::query()->whereKey($filters->agentId)->withoutProtectedFor($context->actor)->value('name');
        }

        if ($filters->connectionId !== null) {
            $descriptions[] = 'Conexión: '.Connection::query()->whereKey($filters->connectionId)->value('name');
        }

        return $descriptions;
    }

    /**
     * Departments the user can see with their visible conversation load,
     * under the same agent and connection filters as the figures above it;
     * empty when the user cannot see departments or conversations.
     */
    public function query(ReportContext $context): Builder
    {
        $actor = $context->actor;
        $filters = $this->filters($context);
        $startOfToday = $context->asOf()->setTimezone($context->timezone)->startOfDay()->utc();

        return Department::query()
            ->visibleTo($actor)
            ->notDeleted()
            ->when(! $this->showsBreakdown($context), fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->when($this->filters($context)->departmentId !== null, fn (Builder $query) => $query->whereKey($this->filters($context)->departmentId))
            ->withCount([
                'conversations as pending_count' => fn (Builder $query) => $this->visible($query, $actor, $filters)->where('status', ConversationStatus::Pending),
                'conversations as attending_count' => fn (Builder $query) => $this->visible($query, $actor, $filters)->whereIn('status', ConversationStatus::attending()),
                'conversations as closed_today_count' => fn (Builder $query) => $this->visible($query, $actor, $filters)->where('status', ConversationStatus::Closed)->where('closed_at', '>=', $startOfToday),
            ])
            ->orderBy('name')
            ->orderBy('id');
    }

    public function columns(): array
    {
        return [
            new Column('name', 'Departamento', width: 34),
            new Column('pending', 'Pendientes', ColumnType::Number, width: 14, total: ColumnTotal::Sum),
            new Column('attending', 'En atención', ColumnType::Number, width: 14, total: ColumnTotal::Sum),
            new Column('closed_today', 'Finalizadas hoy', ColumnType::Number, width: 14, total: ColumnTotal::Sum),
        ];
    }

    /**
     * @param  Department  $record
     */
    public function map(Model $record, ReportContext $context): array
    {
        return [
            'name' => $record->name,
            'pending' => (int) $record->getAttribute('pending_count'),
            'attending' => (int) $record->getAttribute('attending_count'),
            'closed_today' => (int) $record->getAttribute('closed_today_count'),
        ];
    }

    public function metrics(ReportContext $context): array
    {
        $overview = $this->overview($context);
        $unavailable = $overview['conversations_visible'] ? 'Aún no hay un canal con tráfico confirmado' : 'Tu rol no incluye conversaciones';
        $conversations = $overview['conversations'];
        $average = $overview['average_first_response_seconds'];
        $live = $overview['scope'] === 'all' ? '' : ' · conversaciones que puedes ver en tu bandeja';

        $metrics = [
            new Metric('Pendientes', $conversations['pending'], $conversations['pending'] === null ? $unavailable : 'Esperan que alguien las acepte'.$live),
            new Metric('En atención', $conversations['active'], $conversations['active'] === null ? $unavailable : 'Con una persona responsable'.$live),
            new Metric('Finalizadas hoy', $conversations['closed_today'], $conversations['closed_today'] === null ? $unavailable : "Desde las 00:00 ({$context->timezone})".$live),
            new Metric(
                'Primera respuesta (promedio)',
                $average === null ? null : $this->duration($average),
                $average === null
                    ? ($overview['messaging_connected'] && $overview['conversations_visible'] ? 'Sin datos suficientes en el periodo' : $unavailable)
                    : 'Primera respuesta de una persona aceptada por el canal; medido en '.number_format($overview['first_response_samples']).' '.($overview['first_response_samples'] === 1 ? 'conversación' : 'conversaciones'),
            ),
            new Metric('Recibidas en el periodo', $overview['received']['value'], $overview['received']['value'] === null ? $unavailable : 'Periodo anterior: '.number_format((int) $overview['received']['previous'])),
        ];

        if ($overview['departments'] !== null) {
            $metrics[] = new Metric('Agentes activos', $overview['agents']['active'], 'Cuentas y roles activos con permiso para atender conversaciones');
            $metrics[] = new Metric('Departamentos activos', $overview['departments']['active'], number_format($overview['departments']['total']).' en total');
        }

        return $metrics;
    }

    /**
     * The same charts as the dashboard, from the same data (chartsFor).
     */
    public function charts(ReportContext $context): array
    {
        $charts = $this->dashboardCharts($context);
        $result = [];

        if ($charts['activity'] !== null) {
            $points = $charts['activity']['points'];
            $unit = $this->unit($charts['activity']['granularity']);
            $result[] = new Chart(
                ChartType::Line,
                'Actividad',
                array_column($points, 'label'),
                [
                    new ChartSeries('Recibidas', array_column($points, 'received')),
                    new ChartSeries('Atendidas', array_column($points, 'attended')),
                ],
                "Conversaciones recibidas y atendidas (de las recibidas) por {$unit} · horario de {$context->timezone}",
            );
        }

        if ($charts['channels'] !== null) {
            $result[] = new Chart(
                ChartType::Bar,
                'Por canal',
                array_column($charts['channels'], 'label'),
                [new ChartSeries('Recibidas', array_column($charts['channels'], 'received'))],
                'Conversaciones recibidas en el periodo por canal',
                array_map(fn (array $row): string => ReportPalette::channel($row['channel']), $charts['channels']),
            );
        }

        if ($charts['statuses'] !== null) {
            $result[] = new Chart(
                ChartType::Donut,
                'Estado de lo recibido',
                array_column($charts['statuses'], 'label'),
                [new ChartSeries('Conversaciones', array_column($charts['statuses'], 'count'))],
                'Cómo están hoy las conversaciones que llegaron en el periodo',
                array_map(fn (array $row): string => ReportPalette::status($row['status']), $charts['statuses']),
            );
        }

        if ($charts['agents'] !== null) {
            $result[] = new Chart(
                ChartType::Bar,
                'Atención por agente',
                array_column($charts['agents'], 'name'),
                [
                    new ChartSeries('Recibidas', array_column($charts['agents'], 'received')),
                    new ChartSeries('Atendidas', array_column($charts['agents'], 'attended')),
                ],
                'Recibidas y atendidas por agente responsable (actual o último)',
            );
        }

        if ($charts['activity'] !== null) {
            // Every bucket keeps its place; one without measured responses is a gap (never a fake 0).
            $points = $charts['activity']['points'];
            $result[] = new Chart(
                ChartType::Line,
                'Tiempo de primera respuesta',
                array_column($points, 'label'),
                [new ChartSeries('Promedio (min)', array_map(fn (array $point): ?float => $point['first_response_seconds'] === null ? null : round($point['first_response_seconds'] / 60, 1), $points))],
                'Promedio por '.$this->unit($charts['activity']['granularity']).' en minutos; los huecos son periodos sin respuestas medidas',
            );
        }

        if ($charts['departments'] !== null) {
            $result[] = new Chart(
                ChartType::Bar,
                'Por departamento',
                array_column($charts['departments'], 'name'),
                [
                    new ChartSeries('Recibidas', array_column($charts['departments'], 'received')),
                    new ChartSeries('Resueltas', array_column($charts['departments'], 'resolved')),
                ],
                'Recibidas y resueltas por departamento actual',
            );
        }

        return $result;
    }

    /**
     * One data sheet per chart, with every bucket or row of the screen.
     */
    public function extraSheets(ReportContext $context): array
    {
        $charts = $this->dashboardCharts($context);
        $sheets = [];

        if ($charts['activity'] !== null) {
            $sheets[] = new ReportSheet(
                'Actividad',
                [
                    new Column('label', ucfirst($this->unit($charts['activity']['granularity'])), width: 14),
                    new Column('received', 'Recibidas', ColumnType::Number, width: 12, total: ColumnTotal::Sum),
                    new Column('attended', 'Atendidas', ColumnType::Number, width: 12, total: ColumnTotal::Sum),
                    new Column('first_response', 'Primera respuesta (min)', ColumnType::Number, width: 18, decimals: 1),
                    new Column('first_response_samples', 'Conversaciones medidas', ColumnType::Number, width: 18, total: ColumnTotal::Sum),
                ],
                array_map(fn (array $point): array => [
                    'label' => $point['label'],
                    'received' => $point['received'],
                    'attended' => $point['attended'],
                    'first_response' => $point['first_response_seconds'] === null ? null : round($point['first_response_seconds'] / 60, 1),
                    'first_response_samples' => $point['first_response_samples'],
                ], $charts['activity']['points']),
                description: "Por fecha de llegada, horario de {$context->timezone}. Atendidas: de las recibidas en ese periodo, las que ya fueron aceptadas, asignadas por una persona o respondidas.",
            );
        }

        if ($charts['channels'] !== null) {
            $sheets[] = new ReportSheet(
                'Por canal',
                [
                    new Column('label', 'Canal', width: 20),
                    new Column('received', 'Recibidas', ColumnType::Number, width: 12, total: ColumnTotal::Sum),
                    new Column('attended', 'Atendidas', ColumnType::Number, width: 12, total: ColumnTotal::Sum),
                ],
                array_map(fn (array $row): array => ['label' => $row['label'], 'received' => $row['received'], 'attended' => $row['attended']], $charts['channels']),
            );
        }

        if ($charts['statuses'] !== null) {
            $sheets[] = new ReportSheet(
                'Estado de lo recibido',
                [
                    new Column('label', 'Estado', width: 20),
                    new Column('count', 'Conversaciones', ColumnType::Number, width: 14, total: ColumnTotal::Sum),
                ],
                array_map(fn (array $row): array => ['label' => $row['label'], 'count' => $row['count']], $charts['statuses']),
            );
        }

        if ($charts['agents'] !== null) {
            $sheets[] = new ReportSheet(
                'Por agente',
                [
                    new Column('name', 'Agente', width: 24),
                    new Column('received', 'Recibidas', ColumnType::Number, width: 12, total: ColumnTotal::Sum),
                    new Column('attended', 'Atendidas', ColumnType::Number, width: 12, total: ColumnTotal::Sum),
                    new Column('first_response', 'Primera respuesta (min)', ColumnType::Number, width: 18, decimals: 1),
                ],
                array_map(fn (array $row): array => [
                    'name' => $row['name'],
                    'received' => $row['received'],
                    'attended' => $row['attended'],
                    'first_response' => $row['first_response_seconds'] === null ? null : round($row['first_response_seconds'] / 60, 1),
                ], $charts['agents']),
            );
        }

        if ($charts['departments'] !== null) {
            $sheets[] = new ReportSheet(
                'Por departamento',
                [
                    new Column('name', 'Departamento', width: 24),
                    new Column('received', 'Recibidas', ColumnType::Number, width: 12, total: ColumnTotal::Sum),
                    new Column('resolved', 'Resueltas', ColumnType::Number, width: 12, total: ColumnTotal::Sum),
                    new Column('first_response', 'Primera respuesta (min)', ColumnType::Number, width: 18, decimals: 1),
                ],
                array_map(fn (array $row): array => [
                    'name' => $row['name'],
                    'received' => $row['received'],
                    'resolved' => $row['resolved'],
                    'first_response' => $row['first_response_seconds'] === null ? null : round($row['first_response_seconds'] / 60, 1),
                ], $charts['departments']),
            );
        }

        return $sheets;
    }

    public function pdfOptions(): PdfOptions
    {
        return new PdfOptions(tableTitle: 'Carga por departamento');
    }

    /**
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    private function visible(Builder $query, User $actor, AnalyticsFilters $filters): Builder
    {
        return $query->visibleTo($actor)
            ->when($filters->agentId !== null, fn (Builder $query) => $query->where('conversations.assigned_user_id', $filters->agentId))
            ->when($filters->connectionId !== null, fn (Builder $query) => $query->where('conversations.connection_id', $filters->connectionId));
    }

    private function showsBreakdown(ReportContext $context): bool
    {
        $overview = $this->overview($context);

        return $overview['departments'] !== null && $overview['conversations']['active'] !== null;
    }

    private function duration(int $seconds): string
    {
        $seconds = max(0, $seconds);

        if ($seconds < 60) {
            return "{$seconds} s";
        }

        if ($seconds < 3600) {
            return intdiv($seconds, 60).' min '.($seconds % 60).' s';
        }

        return intdiv($seconds, 3600).' h '.intdiv($seconds % 3600, 60).' min';
    }

    /**
     * @param  'hour'|'day'|'month'  $granularity
     */
    private function unit(string $granularity): string
    {
        return match ($granularity) {
            'hour' => 'hora',
            'day' => 'día',
            'month' => 'mes',
        };
    }

    /**
     * @return Charts
     */
    private function dashboardCharts(ReportContext $context): array
    {
        return $this->charts[$context->actor->id] ??= $this->dashboard->chartsFor($context->actor, $this->filters($context));
    }

    /**
     * @return Overview
     */
    private function overview(ReportContext $context): array
    {
        return $this->overviews[$context->actor->id] ??= $this->dashboard->overviewFor($context->actor, $this->filters($context), $context->asOf());
    }

    private function filters(ReportContext $context): AnalyticsFilters
    {
        return $this->filters[spl_object_id($context)] ??= AnalyticsFilters::from($context->filters, $context->actor, $this->scope, $context->timezone, $context->asOf());
    }
}
