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
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportSheet;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\AnalyticsScope;
use App\Services\Analytics\OperationalAnalytics;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Export of the reports module: the same figures, filters and reach as the
 * /reports screen (both read OperationalAnalytics with the same
 * AnalyticsFilters), with the per-agent performance as main table and the
 * breakdowns per department, connection, day and hour as further sections.
 *
 * @phpstan-import-type Analytics from OperationalAnalytics
 * @phpstan-import-type BreakdownRow from OperationalAnalytics
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
            'Alcance: '.match ($this->scope->label($context->actor)) {
                'all' => 'toda la organización',
                'departments' => 'tus departamentos',
                default => 'tus conversaciones',
            },
        ];

        if ($filters->departmentId !== null) {
            $descriptions[] = 'Departamento: '.Department::query()->whereKey($filters->departmentId)->value('name');
        }

        if ($filters->agentId !== null) {
            $descriptions[] = 'Agente: '.User::query()->whereKey($filters->agentId)->value('name');
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
            ->orderBy('agent_id');

        return ConversationMetric::query()->setQuery($cohort);
    }

    public function count(ReportContext $context): int
    {
        return count($this->report($context)['by_agent']);
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
        $agentId = $agentId === null ? null : (int) $agentId;

        foreach ($this->report($context)['by_agent'] as $row) {
            if ($row['id'] === $agentId) {
                return $this->row($row);
            }
        }

        return ['name' => 'Sin datos'];
    }

    public function metrics(ReportContext $context): array
    {
        $summary = $this->report($context)['summary'];
        $current = $this->report($context)['current'];

        return [
            new Metric('Recibidas', $summary['received']['value'], $this->versus($summary['received']['previous'])),
            new Metric('Atendidas', $summary['attended']['value'], 'Aceptadas o asignadas a un agente'),
            new Metric('Resueltas', $summary['resolved']['value'], 'Cerradas al menos una vez'),
            new Metric('Primera respuesta humana (prom.)', $this->duration($summary['first_response_seconds']['value']), $this->samples($summary['first_response_seconds']['samples'], $summary['first_response_seconds']['previous'])),
            new Metric('Primera atención (prom.)', $this->duration($summary['first_attention_seconds']['value']), $this->samples($summary['first_attention_seconds']['samples'], $summary['first_attention_seconds']['previous'])),
            new Metric('Resolución (prom.)', $this->duration($summary['resolution_seconds']['value']), $this->samples($summary['resolution_seconds']['samples'], $summary['resolution_seconds']['previous'])),
            new Metric('Reabiertas', $summary['reopened']['value'], 'Conversaciones reabiertas al menos una vez'),
            new Metric('Transferencias', $summary['transfers']['value'], $this->versus($summary['transfers']['previous'])),
            new Metric('Fuera de horario', $summary['out_of_hours']['value'], $summary['out_of_hours']['value'] === null ? 'Sin horario laboral configurado' : 'Recibidas fuera del horario laboral'),
            new Metric('Mensajes recibidos / enviados', number_format((int) $summary['messages_in']['value']).' / '.number_format((int) $summary['messages_out']['value']), 'De las conversaciones del periodo'),
            new Metric('Errores de envío', $summary['failed_out']['value'], $summary['failure_rate']['value'] === null ? 'Sin mensajes enviados' : $summary['failure_rate']['value'].' % de los enviados'),
            new Metric('Pendientes ahora', $current['pending'], 'En cola sin agente (estado actual)'),
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
            ),
        ];

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
            description: 'Número de WhatsApp por el que llegó cada conversación.',
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
        return $this->filters[spl_object_id($context)] ??= AnalyticsFilters::from($context->filters, $context->actor, $this->scope, $context->timezone, $context->generatedAt);
    }

    /**
     * @return Analytics
     */
    private function report(ReportContext $context): array
    {
        $filters = $this->filters($context);

        return $this->reports["{$context->actor->id}:{$filters->cacheKey()}"] ??= $this->analytics->report($context->actor, $filters);
    }
}
