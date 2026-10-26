<?php

namespace App\Reports\Definitions;

use App\Enums\Permission;
use App\Models\Connection;
use App\Models\ConversationMetric;
use App\Models\Department;
use App\Models\User;
use App\Reports\Chart;
use App\Reports\ChartSeries;
use App\Reports\ChartType;
use App\Reports\Column;
use App\Reports\ColumnTotal;
use App\Reports\ColumnType;
use App\Reports\Metric;
use App\Reports\Orientation;
use App\Reports\PdfOptions;
use App\Reports\Rendering\ReportPalette;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportSheet;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\AnalyticsScope;
use App\Services\Analytics\OperationalAnalytics;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Export of the reports module: the same figures, filters and reach as the
 * /reports screen (both read OperationalAnalytics with the same
 * AnalyticsFilters), with the per-agent performance as main table and the
 * breakdowns per department, connection, day and hour as further sections.
 *
 * The export computes the figures without the screen cache, inside the
 * consistent snapshot of ReportExporter::render(): the rows of the main
 * table, their totals and the summary come from the same data and the rows
 * follow the order of the screen (most received first, ties by agent id).
 * The screen may show figures up to OperationalAnalytics::REPORT_TTL older.
 *
 * @phpstan-import-type Analytics from OperationalAnalytics
 * @phpstan-import-type BreakdownRow from OperationalAnalytics
 * @phpstan-import-type Summary from OperationalAnalytics
 *
 * @extends ReportDefinition<ConversationMetric>
 */
class AnalyticsReport extends ReportDefinition
{
    private const WEEKDAYS = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

    /**
     * Figures computed once per export.
     *
     * @var array<string, Analytics>
     */
    private array $reports = [];

    /**
     * Agent rows by real id, computed once per export.
     *
     * @var array<string, array<int, BreakdownRow>>
     */
    private array $agentRows = [];

    /**
     * Filters resolved once per export context.
     *
     * @var array<int, AnalyticsFilters>
     */
    private array $filters = [];

    public function __construct(
        private OperationalAnalytics $analytics,
        private AnalyticsScope $scope,
    ) {}

    public function key(): string
    {
        return 'analytics';
    }

    public function title(): string
    {
        return 'Reporte operativo';
    }

    public function subtitle(): ?string
    {
        return 'Atención, tiempos y volumen de las conversaciones recibidas en el periodo';
    }

    public function fileName(): string
    {
        return 'reporte-operativo';
    }

    public function orientation(): Orientation
    {
        return Orientation::Landscape;
    }

    public function authorize(User $actor): bool
    {
        return $actor->holdsPermission(Permission::ViewReports);
    }

    public function rules(): array
    {
        return AnalyticsFilters::rules();
    }

    public function describeFilters(ReportContext $context): array
    {
        $filters = $this->filters($context);
        $period = $filters->period;
        $previous = $period->previous();
        $descriptions = [
            "Periodo: {$period->label()} ({$period->timezone})",
            "Comparado con: {$previous->label()}",
            'Incluye: '.match ($this->scope->label($context->actor)) {
                'all' => 'toda la organización',
                'departments' => 'tus departamentos',
                default => 'tus conversaciones',
            },
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
     * One record per agent of the period (the figures come from the same
     * computation as the screen, see map()).
     */
    public function query(ReportContext $context): Builder
    {
        $cohort = $this->analytics->cohort($context->actor, $this->filters($context))
            ->select('agent_id')
            ->groupBy('agent_id')
            ->orderByRaw('COUNT(*) DESC')
            ->orderBy('agent_id');

        return ConversationMetric::query()->setQuery($cohort);
    }

    /**
     * Agents of the period, counted without computing the whole report (the
     * count runs before the export snapshot starts).
     */
    public function count(ReportContext $context): int
    {
        return DB::query()->fromSub($this->query($context)->toBase(), 'agents')->count();
    }

    public function columns(): array
    {
        return [
            new Column('name', 'Agente', width: 24),
            ...$this->figureColumns(),
            new Column('current_load', 'Carga actual', ColumnType::Number, width: 11, total: ColumnTotal::Sum),
        ];
    }

    /**
     * @param  ConversationMetric  $record
     */
    public function map(Model $record, ReportContext $context): array
    {
        $agentId = $record->getAttribute('agent_id');
        $row = $this->agentRows($context)[$agentId === null ? 0 : (int) $agentId] ?? null;

        // Rows and figures are read from the same snapshot (see the class).
        if ($row === null) {
            throw new LogicException('The agent of an exported row is missing from the computed report.');
        }

        return $this->row($row);
    }

    public function metrics(ReportContext $context): array
    {
        $summary = $this->report($context)['summary'];
        $current = $this->report($context)['current'];

        return [
            new Metric('Recibidas', $summary['received']['value'], $this->versus($summary['received']['previous'])),
            new Metric('Atendidas', $summary['attended']['value'], 'Aceptadas, asignadas por una persona o respondidas (la distribución automática no cuenta)'),
            new Metric('Resueltas', $summary['resolved']['value'], 'Cerradas al menos una vez'),
            new Metric('Primera respuesta (promedio, tiempo real)', $this->duration($summary['first_response_seconds']['value']), $this->samples($summary['first_response_seconds']['samples'], $summary['first_response_seconds']['previous'])),
            new Metric('Primera respuesta (promedio, horario de atención)', $this->duration($summary['first_response_business_seconds']['value']), $this->samples($summary['first_response_business_seconds']['samples'], $summary['first_response_business_seconds']['previous'])),
            new Metric('Primera atención (promedio, tiempo real)', $this->duration($summary['first_attention_seconds']['value']), $this->samples($summary['first_attention_seconds']['samples'], $summary['first_attention_seconds']['previous'])),
            new Metric('Resolución (promedio, tiempo real)', $this->duration($summary['resolution_seconds']['value']), $this->samples($summary['resolution_seconds']['samples'], $summary['resolution_seconds']['previous'])),
            new Metric('Resolución (promedio, horario de atención)', $this->duration($summary['resolution_business_seconds']['value']), $this->samples($summary['resolution_business_seconds']['samples'], $summary['resolution_business_seconds']['previous'])),
            ...$this->slaMetrics($summary),
            new Metric('Reabiertas', $summary['reopened']['value'], 'Conversaciones reabiertas al menos una vez'),
            new Metric('Transferencias', $summary['transfers']['value'], $this->versus($summary['transfers']['previous'])),
            new Metric('Fuera de horario', $summary['out_of_hours']['value'], $summary['out_of_hours']['value'] === null ? 'Sin horario laboral configurado' : 'Recibidas fuera del horario laboral'),
            new Metric('Mensajes recibidos / enviados', number_format((int) $summary['messages_in']['value']).' / '.number_format((int) $summary['messages_out']['value']), 'De las conversaciones del periodo'),
            new Metric('Errores de envío', $summary['failed_out']['value'], $summary['failure_rate']['value'] === null ? 'Sin mensajes enviados' : $summary['failure_rate']['value'].' % de los enviados'),
            new Metric('Pendientes ahora', $current['pending'], 'Esperan que alguien las acepte (ahora)'),
        ];
    }

    public function charts(ReportContext $context): array
    {
        $report = $this->report($context);
        $points = $report['trend']['points'];
        $hours = array_fill(0, 24, 0);

        foreach ($report['heatmap']['cells'] as $day) {
            foreach ($day as $hour => $count) {
                $hours[$hour] += $count;
            }
        }

        $charts = [
            new Chart(
                ChartType::Line,
                'Conversaciones recibidas',
                array_column($points, 'label'),
                [
                    new ChartSeries('Periodo', array_column($points, 'received')),
                    new ChartSeries('Periodo anterior', array_column($points, 'previous_received')),
                ],
                $report['trend']['granularity'] === 'day' ? 'Por día' : 'Por mes',
            ),
            new Chart(
                ChartType::Bar,
                'Demanda por hora del día',
                array_map(fn (int $hour): string => sprintf('%02d', $hour), range(0, 23)),
                [new ChartSeries('Conversaciones', array_values($hours))],
                'Hora de recepción (zona horaria de la plataforma)',
            ),
            new Chart(
                ChartType::Donut,
                'Estado actual de las recibidas',
                array_column($report['statuses'], 'label'),
                [new ChartSeries('Conversaciones', array_column($report['statuses'], 'count'))],
                colors: array_map(fn (array $row): string => ReportPalette::status($row['status']), $report['statuses']),
            ),
        ];

        // Every bucket keeps its place; one without measured responses is a gap (never a fake 0).
        $charts[] = new Chart(
            ChartType::Line,
            'Tiempo de primera respuesta',
            array_column($report['activity']['points'], 'label'),
            [new ChartSeries('Promedio (min)', array_map(fn (array $point): ?float => $point['first_response_seconds'] === null ? null : round($point['first_response_seconds'] / 60, 1), $report['activity']['points']))],
            'Promedio por '.$this->unit($report['activity']['granularity']).' en minutos; los huecos son periodos sin respuestas medidas',
        );

        $charts[] = new Chart(
            ChartType::Bar,
            'Conversaciones por canal',
            array_column($report['by_channel'], 'label'),
            [
                new ChartSeries('Recibidas', array_column($report['by_channel'], 'received')),
                new ChartSeries('Atendidas', array_column($report['by_channel'], 'attended')),
            ],
            'Recibidas en el periodo',
        );

        if ($report['by_department'] !== []) {
            $top = array_slice($report['by_department'], 0, 10);
            $charts[] = new Chart(
                ChartType::Bar,
                'Conversaciones por departamento',
                array_column($top, 'name'),
                [new ChartSeries('Recibidas', array_column($top, 'received'))],
                'Top 10 del periodo',
            );
        }

        return $charts;
    }

    public function extraSheets(ReportContext $context): array
    {
        $report = $this->report($context);
        $sheets = [];

        if ($report['by_department'] !== []) {
            $sheets[] = new ReportSheet(
                'Por departamento',
                [new Column('name', 'Departamento', width: 24), ...$this->figureColumns(), new Column('current_load', 'Carga actual', ColumnType::Number, width: 11, total: ColumnTotal::Sum)],
                array_map(fn (array $row): array => $this->row($row), $report['by_department']),
                inPdf: true,
                newPage: true,
                description: 'Departamento actual de cada conversación recibida en el periodo.',
            );
        }

        $sheets[] = new ReportSheet(
            'Por conexión',
            [new Column('name', 'Conexión', width: 24), ...$this->figureColumns()],
            array_map(fn (array $row): array => $this->row($row), $report['by_connection']),
            inPdf: true,
            description: 'Conexión (número de WhatsApp o chat web) por la que llegó cada conversación.',
        );

        $sheets[] = new ReportSheet(
            'Por canal',
            [
                new Column('label', 'Canal', width: 20),
                new Column('received', 'Recibidas', ColumnType::Number, width: 12, total: ColumnTotal::Sum),
                new Column('attended', 'Atendidas', ColumnType::Number, width: 12, total: ColumnTotal::Sum),
            ],
            array_map(fn (array $row): array => ['label' => $row['label'], 'received' => $row['received'], 'attended' => $row['attended']], $report['by_channel']),
            description: 'Suma de las conexiones de cada canal (incluidas las eliminadas).',
        );

        $sheets[] = new ReportSheet(
            'Tendencia',
            [
                new Column('label', $report['trend']['granularity'] === 'day' ? 'Día' : 'Mes', width: 14),
                new Column('received', 'Recibidas', ColumnType::Number, width: 12, total: ColumnTotal::Sum),
                new Column('resolved', 'Resueltas ese día', ColumnType::Number, width: 14, total: ColumnTotal::Sum),
                new Column('previous_received', 'Recibidas periodo anterior', ColumnType::Number, width: 18, total: ColumnTotal::Sum),
            ],
            array_map(fn (array $point): array => [
                'label' => $point['label'],
                'received' => $point['received'],
                'resolved' => $point['resolved'],
                'previous_received' => $point['previous_received'],
            ], $report['trend']['points']),
            description: 'Recibidas por fecha de llegada; resueltas por fecha de su primer cierre.',
        );

        $sheets[] = new ReportSheet(
            'Actividad',
            [
                new Column('label', ucfirst($this->unit($report['activity']['granularity'])), width: 14),
                new Column('received', 'Recibidas', ColumnType::Number, width: 12, total: ColumnTotal::Sum),
                new Column('attended', 'Atendidas', ColumnType::Number, width: 12, total: ColumnTotal::Sum),
                new Column('first_response', 'Primera respuesta (min)', ColumnType::Number, width: 18, decimals: 1),
                new Column('first_response_samples', 'Conversaciones medidas', ColumnType::Number, width: 18, total: ColumnTotal::Sum),
            ],
            array_map(fn (array $point): array => [
                'label' => $point['label'],
                'received' => $point['received'],
                'attended' => $point['attended'],
                'first_response' => $this->minutes($point['first_response_seconds']),
                'first_response_samples' => $point['first_response_samples'],
            ], $report['activity']['points']),
            description: 'Por fecha de llegada. Atendidas: de las recibidas en ese periodo, las que ya fueron aceptadas o asignadas.',
        );

        $hourColumns = [new Column('day', 'Día', width: 12)];

        foreach (range(0, 23) as $hour) {
            $hourColumns[] = new Column("h{$hour}", sprintf('%02d', $hour), ColumnType::Number, width: 5);
        }

        $sheets[] = new ReportSheet(
            'Demanda por hora',
            $hourColumns,
            array_map(function (array $hours, int $day): array {
                $row = ['day' => self::WEEKDAYS[$day]];

                foreach ($hours as $hour => $count) {
                    $row["h{$hour}"] = $count;
                }

                return $row;
            }, $report['heatmap']['cells'], array_keys($report['heatmap']['cells'])),
            description: 'Conversaciones recibidas por día de la semana y hora local.',
        );

        return $sheets;
    }

    public function pdfOptions(): PdfOptions
    {
        return new PdfOptions(tableTitle: 'Desempeño por agente');
    }

    /**
     * @return list<Column>
     */
    private function figureColumns(): array
    {
        return [
            new Column('received', 'Recibidas', ColumnType::Number, width: 10, total: ColumnTotal::Sum),
            new Column('attended', 'Atendidas', ColumnType::Number, width: 10, total: ColumnTotal::Sum),
            new Column('responded', 'Respondidas', ColumnType::Number, width: 11, total: ColumnTotal::Sum),
            new Column('resolved', 'Resueltas', ColumnType::Number, width: 10, total: ColumnTotal::Sum),
            new Column('first_attention', '1ª atención (min)', ColumnType::Number, width: 12, decimals: 1),
            new Column('first_response', '1ª respuesta (min)', ColumnType::Number, width: 12, decimals: 1),
            new Column('resolution', 'Resolución (min)', ColumnType::Number, width: 12, decimals: 1),
            new Column('transfers', 'Transferencias', ColumnType::Number, width: 12, total: ColumnTotal::Sum),
            new Column('reopened', 'Reabiertas', ColumnType::Number, width: 10, total: ColumnTotal::Sum),
            new Column('messages_out', 'Mensajes enviados', ColumnType::Number, width: 12, total: ColumnTotal::Sum),
            new Column('failed_out', 'Errores de envío', ColumnType::Number, width: 11, total: ColumnTotal::Sum),
        ];
    }

    /**
     * @param  BreakdownRow  $row
     * @return array<string, string|int|float|null>
     */
    private function row(array $row): array
    {
        return [
            'name' => $row['name'],
            'received' => $row['received'],
            'attended' => $row['attended'],
            'responded' => $row['responded'],
            'resolved' => $row['resolved'],
            'first_attention' => $this->minutes($row['first_attention_seconds']),
            'first_response' => $this->minutes($row['first_response_seconds']),
            'resolution' => $this->minutes($row['resolution_seconds']),
            'transfers' => $row['transfers'],
            'reopened' => $row['reopened'],
            'messages_out' => $row['messages_out'],
            'failed_out' => $row['failed_out'],
            'current_load' => $row['current_load'],
        ];
    }

    /**
     * Compliance cards, only for the objectives that are configured.
     *
     * @param  Summary  $summary
     * @return list<Metric>
     */
    private function slaMetrics(array $summary): array
    {
        $metrics = [];

        foreach (['first_response_sla' => 'primera respuesta', 'resolution_sla' => 'resolución'] as $key => $label) {
            $sla = $summary[$key];

            if ($sla === null) {
                continue;
            }

            $metrics[] = new Metric(
                "Objetivo de {$label}",
                $sla['rate'] === null ? null : "{$sla['rate']} %",
                $sla['samples'] === 0
                    ? "Objetivo {$this->duration($sla['target_seconds'])} en horario de atención · sin conversaciones medidas"
                    : "{$sla['met']} de {$sla['samples']} dentro de {$this->duration($sla['target_seconds'])} en horario de atención",
            );
        }

        return $metrics;
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

    private function minutes(?int $seconds): ?float
    {
        return $seconds === null ? null : round($seconds / 60, 1);
    }

    private function duration(?int $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }

        return match (true) {
            $seconds < 60 => "{$seconds} s",
            $seconds < 3600 => intdiv($seconds, 60).' min '.($seconds % 60).' s',
            default => intdiv($seconds, 3600).' h '.intdiv($seconds % 3600, 60).' min',
        };
    }

    private function versus(int|float|null $previous): string
    {
        return $previous === null ? 'Sin datos del periodo anterior' : 'Periodo anterior: '.number_format((float) $previous);
    }

    private function samples(int $samples, ?int $previous): string
    {
        if ($samples === 0) {
            return 'Sin datos suficientes';
        }

        $text = number_format($samples).' '.($samples === 1 ? 'conversación medida' : 'conversaciones medidas');

        return $previous === null ? $text : "{$text}; antes {$this->duration($previous)}";
    }

    private function filters(ReportContext $context): AnalyticsFilters
    {
        return $this->filters[spl_object_id($context)] ??= AnalyticsFilters::from($context->filters, $context->actor, $this->scope, $context->timezone, $context->asOf());
    }

    /**
     * Rows of the agents of the period keyed by their real id (0 without
     * agent), so each hidden protected account keeps its own figures. They
     * are computed inside the same export snapshot as the report.
     *
     * @return array<int, BreakdownRow>
     */
    private function agentRows(ReportContext $context): array
    {
        $key = "{$context->actor->id}:{$this->filters($context)->cacheKey()}";

        if (! isset($this->agentRows[$key])) {
            $this->agentRows[$key] = [];

            foreach ($this->analytics->breakdown($context->actor, $this->filters($context), 'agent_id', withSourceIds: true) as $row) {
                $sourceId = $row['source_id'];
                unset($row['source_id']);
                $this->agentRows[$key][$sourceId ?? 0] = $row;
            }
        }

        return $this->agentRows[$key];
    }

    /**
     * @return Analytics
     */
    private function report(ReportContext $context): array
    {
        $filters = $this->filters($context);

        return $this->reports["{$context->actor->id}:{$filters->cacheKey()}"] ??= $this->analytics->compute($context->actor, $filters);
    }
}
