<?php

namespace App\Reports\Definitions;

use App\Enums\Permission;
use App\Models\ConversationMetric;
use App\Models\User;
use App\Reports\Chart;
use App\Reports\ChartSeries;
use App\Reports\ChartType;
use App\Reports\Column;
use App\Reports\ColumnTotal;
use App\Reports\ColumnType;
use App\Reports\Concerns\DescribesAnalytics;
use App\Reports\Metric;
use App\Reports\Orientation;
use App\Reports\PdfOptions;
use App\Reports\Rendering\ReportPalette;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportSheet;
use App\Services\Analytics\AgentProductivity;
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
    use DescribesAnalytics;

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
        return $this->describeAnalyticsFilters($this->filters($context), $context->actor, $this->scope->label($context->actor));
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
            new Column('name', 'Responsable actual', width: 24),
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

        $backlog = $this->report($context)['backlog'];
        $received = (int) $summary['received']['value'];

        return [
            new Metric('Recibidas', $summary['received']['value'], $this->versus($summary['received']['previous'])),
            new Metric('Atendidas', $summary['attended']['value'], $this->share((int) $summary['attended']['value'], $received).'Aceptadas, asignadas por una persona o respondidas (la distribución automática no cuenta)'),
            new Metric('Finalizadas (de las recibidas)', $summary['resolved']['value'], $this->share((int) $summary['resolved']['value'], $received).'Recibidas en el periodo y cerradas al menos una vez'),
            new Metric('Finalizadas en el periodo', $summary['closed_in_period']['value'], 'Primer cierre dentro del periodo, sin importar cuándo llegaron. '.$this->versus($summary['closed_in_period']['previous'])),
            new Metric('Acumuladas sin atender (ahora)', $backlog['now'], 'Sin atención humana ni cierre; al inicio del periodo: '.number_format($backlog['start'])),
            new Metric('Pendientes ahora', $current['pending'], 'Esperan que alguien las acepte (ahora)'),
            new Metric('Espera hasta la primera respuesta (promedio, tiempo real)', $this->duration($summary['first_response_seconds']['value']), $this->samples($summary['first_response_seconds']['samples'], $summary['first_response_seconds']['previous'])),
            new Metric('Primera respuesta más rápida / más lenta', $summary['first_response_range']['min'] === null ? null : $this->duration($summary['first_response_range']['min']).' / '.$this->duration($summary['first_response_range']['max']), 'Mínimo y máximo de las conversaciones medidas'),
            new Metric('Espera hasta la primera respuesta (promedio, horario de atención)', $this->duration($summary['first_response_business_seconds']['value']), $this->samples($summary['first_response_business_seconds']['samples'], $summary['first_response_business_seconds']['previous'])),
            new Metric('Primera atención (promedio, tiempo real)', $this->duration($summary['first_attention_seconds']['value']), $this->samples($summary['first_attention_seconds']['samples'], $summary['first_attention_seconds']['previous'])),
            new Metric('Duración hasta la resolución (promedio, tiempo real)', $this->duration($summary['resolution_seconds']['value']), $this->samples($summary['resolution_seconds']['samples'], $summary['resolution_seconds']['previous'])),
            new Metric('Duración hasta la resolución (promedio, horario de atención)', $this->duration($summary['resolution_business_seconds']['value']), $this->samples($summary['resolution_business_seconds']['samples'], $summary['resolution_business_seconds']['previous'])),
            ...$this->cycleMetrics($this->report($context)['sla']),
            ...$this->slaMetrics($summary),
            new Metric('Reabiertas', $summary['reopened']['value'], 'Conversaciones reabiertas al menos una vez'),
            new Metric('Transferencias', $summary['transfers']['value'], $this->versus($summary['transfers']['previous'])),
            new Metric('Fuera de horario', $summary['out_of_hours']['value'], $summary['out_of_hours']['value'] === null ? 'Sin horario laboral configurado' : 'Recibidas fuera del horario laboral'),
            new Metric('Mensajes entrantes / salientes', number_format((int) $summary['messages_in']['value']).' / '.number_format((int) $summary['messages_out']['value']), 'Mensajes (no solicitudes) de las conversaciones del periodo; los salientes incluyen automáticos'),
            new Metric('Errores de envío', $summary['failed_out']['value'], $summary['failure_rate']['value'] === null ? 'Sin mensajes enviados' : $summary['failure_rate']['value'].' % de los salientes'),
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
                'Por '.$this->bucketUnit($report['trend']['granularity']),
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
            'Promedio por '.$this->bucketUnit($report['activity']['granularity']).' en minutos; los huecos son periodos sin respuestas medidas',
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

        $sla = $report['sla'];

        if ($sla['totals']['samples'] > 0) {
            $charts[] = new Chart(
                ChartType::Donut,
                'Cumplimiento de SLA',
                ['Dentro del plazo', 'Fuera del plazo'],
                [new ChartSeries('Ciclos de respuesta', [$sla['totals']['met'], $sla['totals']['breached']])],
                'Ciclos de respuesta con objetivo iniciados en el periodo (n = '.number_format($sla['totals']['samples']).')',
                [ReportPalette::status('closed'), ReportPalette::status('failed')],
            );
        }

        $charts[] = new Chart(
            ChartType::Line,
            'Acumuladas sin atender',
            array_column($report['backlog']['points'], 'label'),
            [new ChartSeries('Sin atender al cierre de cada '.$this->bucketUnit($report['backlog']['granularity']), array_column($report['backlog']['points'], 'backlog'))],
            'Solicitudes recibidas que aún no tenían atención humana ni cierre',
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
            'Productividad',
            $this->productivityColumns(),
            array_map(fn (array $row): array => $this->productivityRow($row), $report['productivity']['rows']),
            inPdf: true,
            newPage: true,
            description: 'Trabajo de cada persona en las solicitudes recibidas en el periodo. Una solicitud atendida por dos personas cuenta para ambas: la suma puede superar las '.number_format($report['productivity']['conversations']).' solicitudes distintas atendidas.'.($report['productivity']['complete'] ? '' : ' '.AgentProductivity::INCOMPLETE_NOTE),
        );

        $sheets[] = new ReportSheet(
            'Por categoría',
            [new Column('name', 'Categoría', width: 24), ...$this->figureColumns()],
            array_map(fn (array $row): array => $this->row($row), $report['by_category']),
            inPdf: true,
            description: 'Categoría actual de cada solicitud recibida en el periodo.',
        );

        $sheets[] = new ReportSheet(
            'SLA por departamento',
            $this->slaGroupColumns('Departamento'),
            array_map(fn (array $row): array => $this->slaGroupRow($row), $report['sla']['by_department']),
            inPdf: $report['sla']['totals']['samples'] > 0,
            description: $this->slaNote($report['sla']),
        );

        $sheets[] = new ReportSheet(
            'Acumulado sin atender',
            [
                new Column('label', ucfirst($this->bucketUnit($report['backlog']['granularity'])), width: 14),
                new Column('received', 'Recibidas', ColumnType::Number, width: 12, total: ColumnTotal::Sum),
                new Column('left', 'Atendidas o cerradas por primera vez', ColumnType::Number, width: 22, total: ColumnTotal::Sum),
                new Column('backlog', 'Sin atender al cierre', ColumnType::Number, width: 16),
            ],
            $report['backlog']['points'],
            description: 'Sin atender = recibidas que aún no tenían atención humana ni cierre al terminar cada periodo. Inicio del periodo: '.number_format($report['backlog']['start']).'. Los periodos sin terminar quedan vacíos.',
        );

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
                new Column('label', ucfirst($this->bucketUnit($report['trend']['granularity'])), width: 14),
                new Column('received', 'Recibidas', ColumnType::Number, width: 12, total: ColumnTotal::Sum),
                new Column('resolved', 'Finalizadas en el periodo (primer cierre)', ColumnType::Number, width: 22, total: ColumnTotal::Sum),
                new Column('previous_received', 'Recibidas periodo anterior', ColumnType::Number, width: 18, total: ColumnTotal::Sum),
            ],
            array_map(fn (array $point): array => [
                'label' => $point['label'],
                'received' => $point['received'],
                'resolved' => $point['resolved'],
                'previous_received' => $point['previous_received'],
            ], $report['trend']['points']),
            description: 'Recibidas por fecha de llegada; finalizadas por la fecha de su primer cierre (cada solicitud cuenta una sola vez).',
        );

        $sheets[] = new ReportSheet(
            'Actividad',
            [
                new Column('label', ucfirst($this->bucketUnit($report['activity']['granularity'])), width: 14),
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
        return new PdfOptions(tableTitle: 'Cartera por responsable actual');
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
            new Column('resolved', 'Finalizadas', ColumnType::Number, width: 10, total: ColumnTotal::Sum),
            new Column('first_attention', '1ª atención (min)', ColumnType::Number, width: 12, decimals: 1),
            new Column('first_response', '1ª respuesta (min)', ColumnType::Number, width: 12, decimals: 1),
            new Column('resolution', 'Resolución (min)', ColumnType::Number, width: 12, decimals: 1),
            new Column('transfers', 'Transferencias', ColumnType::Number, width: 12, total: ColumnTotal::Sum),
            new Column('reopened', 'Reabiertas', ColumnType::Number, width: 10, total: ColumnTotal::Sum),
            new Column('messages_out', 'Mensajes salientes (incl. automáticos)', ColumnType::Number, width: 14, total: ColumnTotal::Sum),
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
     * Service level from the response cycles (frozen objectives).
     *
     * @param  array<string, mixed>  $sla
     * @return list<Metric>
     */
    private function cycleMetrics(array $sla): array
    {
        $totals = $sla['totals'];
        $times = $sla['response_time'];

        return [
            new Metric(
                'Cumplimiento de SLA',
                $this->spellRate($totals['rate']),
                $totals['samples'] === 0
                    ? 'Sin datos: ningún ciclo de respuesta con objetivo evaluado en el periodo. '.$this->slaNote($sla)
                    : number_format($totals['met']).' dentro y '.number_format($totals['breached']).' fuera del plazo (n = '.number_format($totals['samples']).'). '.$this->slaNote($sla),
                ColumnType::Text,
            ),
            new Metric(
                'Tiempo de respuesta por mensaje del cliente (promedio)',
                $this->duration($times['avg']),
                $times['samples'] === 0
                    ? 'Sin ciclos respondidos en el periodo'
                    : 'Mínimo '.$this->duration($times['min']).' · máximo '.$this->duration($times['max']).' · n = '.number_format($times['samples']).' ciclos respondidos',
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $sla
     */
    private function slaNote(array $sla): string
    {
        return match (true) {
            $sla['available_since'] === null => 'El seguimiento de SLA por ciclo aún no tiene registros.',
            ! $sla['covers_period'] => 'Disponible desde '.$sla['available_since'].': el periodo es anterior y la cifra es parcial.',
            default => 'Objetivo congelado al iniciar cada ciclo; los ciclos sin objetivo no cuentan.',
        };
    }

    /**
     * @return list<Column>
     */
    private function slaGroupColumns(string $dimension): array
    {
        return [
            new Column('name', $dimension, width: 24),
            new Column('met', 'Dentro del plazo', ColumnType::Number, width: 14, total: ColumnTotal::Sum),
            new Column('breached', 'Fuera del plazo', ColumnType::Number, width: 14, total: ColumnTotal::Sum),
            new Column('in_progress', 'En curso', ColumnType::Number, width: 10, total: ColumnTotal::Sum),
            new Column('samples', 'Ciclos evaluados (n)', ColumnType::Number, width: 16, total: ColumnTotal::Sum),
            new Column('rate', 'Cumplimiento', ColumnType::Percent, width: 13),
            // Never one average: each cycle's overrun is in the unit of its own objective.
            new Column('exceeded_business', 'Exceso promedio en tiempo hábil (min)', ColumnType::Number, width: 20, decimals: 1),
            new Column('exceeded_calendar', 'Exceso promedio en tiempo corrido (min)', ColumnType::Number, width: 20, decimals: 1),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, string|int|float|null>
     */
    private function slaGroupRow(array $row): array
    {
        return [
            'name' => $row['name'],
            'met' => $row['met'],
            'breached' => $row['breached'],
            'in_progress' => $row['in_progress'],
            'samples' => $row['samples'],
            'rate' => $row['rate'] === null ? null : $row['rate'] / 100,
            'exceeded_business' => $this->toMinutes($row['exceeded_business_seconds']),
            'exceeded_calendar' => $this->toMinutes($row['exceeded_calendar_seconds']),
        ];
    }

    /**
     * @return list<Column>
     */
    private function productivityColumns(): array
    {
        return AgentProductivityReport::figureColumns();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, string|int|float|null>
     */
    private function productivityRow(array $row): array
    {
        return AgentProductivityReport::figureRow($row);
    }

    private function share(int $part, int $total): string
    {
        return $total > 0 ? number_format($part / $total * 100, 1).' % de las recibidas. ' : '';
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

            // Reference only: today's general objective over the conversations already measured.
            $metrics[] = new Metric(
                "Referencia: {$label} dentro del objetivo general vigente",
                $sla['rate'] === null ? null : "{$sla['rate']} %",
                ($sla['samples'] === 0
                    ? "Objetivo {$this->duration($sla['target_seconds'])} en horario de atención · sin conversaciones medidas"
                    : "{$sla['met']} de {$sla['samples']} dentro de {$this->duration($sla['target_seconds'])} en horario de atención")
                    .'. No cuenta las que siguen sin '.($key === 'first_response_sla' ? 'respuesta' : 'cierre').' y usa el objetivo de hoy; el cumplimiento oficial es «Cumplimiento de SLA».',
            );
        }

        return $metrics;
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
