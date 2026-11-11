<?php

namespace App\Reports\Definitions;

use App\Enums\Permission;
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
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportSheet;
use App\Services\Analytics\AgentProductivity;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\AnalyticsScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Productivity per collaborator: the same rows, filters and reach as the
 * «Productividad» view of /reports (both read AgentProductivity), computed
 * without the screen cache inside the consistent snapshot of the export.
 *
 * @phpstan-import-type SourcedAgentRow from AgentProductivity
 * @phpstan-import-type Evolution from AgentProductivity
 *
 * @extends ReportDefinition<User>
 */
class AgentProductivityReport extends ReportDefinition
{
    use DescribesAnalytics;

    /**
     * Rows computed once per export, keyed by real user id.
     *
     * @var array<int, array<int, SourcedAgentRow>>
     */
    private array $rows = [];

    /**
     * @var array<int, AnalyticsFilters>
     */
    private array $filters = [];

    public function __construct(
        private AgentProductivity $productivity,
        private AnalyticsScope $scope,
    ) {}

    public function key(): string
    {
        return 'agent-productivity';
    }

    public function title(): string
    {
        return 'Productividad por colaborador';
    }

    public function subtitle(): ?string
    {
        return 'Trabajo de cada persona en las solicitudes recibidas en el periodo';
    }

    public function fileName(): string
    {
        return 'productividad-por-colaborador';
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
        return $this->describeAnalyticsFilters($this->filters($context), $context->actor, $this->scope->label($context->actor), withComparison: false);
    }

    /**
     * One record per person of the report, in the order of the screen.
     */
    public function query(ReportContext $context): Builder
    {
        $ids = array_keys($this->agentRows($context));

        return User::query()
            ->whereKey($ids)
            ->when($ids !== [], fn (Builder $query) => $query->orderByRaw('FIELD(id, '.implode(', ', array_fill(0, count($ids), '?')).')', $ids));
    }

    public function count(ReportContext $context): int
    {
        return count($this->agentRows($context));
    }

    public function columns(): array
    {
        return self::figureColumns();
    }

    /**
     * @param  User  $record
     */
    public function map(Model $record, ReportContext $context): array
    {
        $row = $this->agentRows($context)[$record->id] ?? null;

        if ($row === null) {
            throw new LogicException('The person of an exported row is missing from the computed productivity.');
        }

        return self::figureRow($row);
    }

    public function metrics(ReportContext $context): array
    {
        $rows = array_values($this->agentRows($context));
        $filters = $this->filters($context);
        $report = $this->productivity->report($context->actor, $filters);
        // Without the per-person rows of the whole period the totals are not known: «Sin datos», never a partial figure as if it were final.
        $complete = $report['complete'];
        $conversations = $complete ? $report['conversations'] : null;
        $met = (int) array_sum(array_column($rows, 'sla_met'));
        $breached = (int) array_sum(array_column($rows, 'sla_breached'));

        return [
            new Metric('Colaboradores con actividad', count($rows), 'Con solicitudes del periodo, carga actual o ciclos de SLA'),
            new Metric('Solicitudes distintas atendidas', $conversations, $complete ? 'Una solicitud atendida por dos personas cuenta para ambas en la tabla, pero una sola vez aquí' : AgentProductivity::INCOMPLETE_NOTE),
            new Metric('Finalizadas (cierres)', $complete ? (int) array_sum(array_column($rows, 'closes')) : null, $complete ? 'Cierres hechos por quien era responsable; un recierre tras reapertura cuenta de nuevo' : AgentProductivity::INCOMPLETE_NOTE),
            new Metric('Pendientes actuales', (int) array_sum(array_column($rows, 'pending_now')), 'Asignadas ahora y sin cerrar (no depende del periodo)'),
            new Metric('Vencidas ahora', (int) array_sum(array_column($rows, 'overdue_now')), 'Esperan respuesta fuera de su plazo (no depende del periodo)'),
            new Metric(
                'Cumplimiento de SLA de los colaboradores',
                $met + $breached > 0 ? $this->spellRate(round($met / ($met + $breached) * 100, 1)) : null,
                $met + $breached > 0 ? number_format($met).' dentro y '.number_format($breached).' fuera del plazo (n = '.number_format($met + $breached).'); sin los vencidos en cola sin responsable' : 'Sin datos: ningún ciclo con objetivo evaluado',
                ColumnType::Text,
            ),
        ];
    }

    public function charts(ReportContext $context): array
    {
        $top = array_slice(array_values($this->agentRows($context)), 0, 10);

        if ($top === []) {
            return [];
        }

        return [
            new Chart(
                ChartType::Bar,
                'Solicitudes atendidas y finalizadas',
                array_column($top, 'name'),
                [
                    new ChartSeries('Atendidas', array_column($top, 'attended')),
                    new ChartSeries('Finalizadas (cierres)', array_column($top, 'closes')),
                ],
                'Los 10 colaboradores con más solicitudes atendidas en el periodo',
            ),
            new Chart(
                ChartType::Bar,
                'Pendientes actuales',
                array_column($top, 'name'),
                [new ChartSeries('Asignadas ahora', array_column($top, 'pending_now'))],
                'Solicitudes asignadas ahora y sin cerrar',
            ),
        ];
    }

    public function extraSheets(ReportContext $context): array
    {
        $evolution = $this->productivity->report($context->actor, $this->filters($context))['evolution'];
        $columns = [new Column('label', ucfirst($this->bucketUnit($evolution['granularity'])), width: 14)];
        $rows = [];

        foreach ($evolution['buckets'] as $index => $bucket) {
            $rows[$index] = ['label' => $bucket['label']];
        }

        foreach ($evolution['agents'] as $position => $agent) {
            $columns[] = new Column("a{$position}", $agent['name'], ColumnType::Number, width: 16, total: ColumnTotal::Sum);

            foreach ($agent['attended'] as $index => $value) {
                $rows[$index]["a{$position}"] = $value;
            }
        }

        return [
            new ReportSheet(
                'Evolución',
                $columns,
                array_values($rows),
                description: 'Solicitudes atendidas por cada persona según la fecha en que llegaron (los '.AgentProductivity::EVOLUTION_AGENTS.' colaboradores con más solicitudes atendidas).',
            ),
        ];
    }

    public function pdfOptions(): PdfOptions
    {
        return new PdfOptions(tableTitle: 'Productividad por colaborador');
    }

    /**
     * Columns of a productivity table (durations in minutes, as numbers).
     *
     * @return list<Column>
     */
    public static function figureColumns(): array
    {
        return [
            new Column('name', 'Colaborador', width: 24),
            new Column('assigned', 'Asignadas', ColumnType::Number, width: 10, total: ColumnTotal::Sum),
            new Column('attended', 'Atendidas', ColumnType::Number, width: 10, total: ColumnTotal::Sum),
            new Column('closes', 'Finalizadas (cierres)', ColumnType::Number, width: 11, total: ColumnTotal::Sum),
            new Column('replies', 'Respuestas enviadas (mensajes)', ColumnType::Number, width: 13, total: ColumnTotal::Sum),
            new Column('first_responses', 'Primeras respuestas', ColumnType::Number, width: 11, total: ColumnTotal::Sum),
            new Column('first_response', 'Espera 1ª respuesta (min)', ColumnType::Number, width: 12, decimals: 1),
            new Column('handling', 'Tiempo de atención (min)', ColumnType::Number, width: 12, decimals: 1),
            new Column('handling_samples', 'Solicitudes con atención concluida (n)', ColumnType::Number, width: 14, total: ColumnTotal::Sum),
            new Column('transfers_out', 'Transferidas a otros', ColumnType::Number, width: 11, total: ColumnTotal::Sum),
            new Column('pending_now', 'Pendientes actuales', ColumnType::Number, width: 11, total: ColumnTotal::Sum),
            new Column('overdue_now', 'Vencidas ahora', ColumnType::Number, width: 10, total: ColumnTotal::Sum),
            new Column('sla_met', 'SLA dentro', ColumnType::Number, width: 9, total: ColumnTotal::Sum),
            new Column('sla_breached', 'SLA fuera', ColumnType::Number, width: 9, total: ColumnTotal::Sum),
            new Column('sla_rate', 'Cumplimiento SLA', ColumnType::Percent, width: 12),
        ];
    }

    /**
     * @param  array<string, mixed>  $row  A row of AgentProductivity::rows().
     * @return array<string, string|int|float|null>
     */
    public static function figureRow(array $row): array
    {
        return [
            'name' => $row['name'],
            'assigned' => $row['assigned'],
            'attended' => $row['attended'],
            'closes' => $row['closes'],
            'replies' => $row['replies'],
            'first_responses' => $row['first_responses'],
            'first_response' => $row['first_response_seconds'] === null ? null : round($row['first_response_seconds'] / 60, 1),
            'handling' => $row['handling_seconds'] === null ? null : round($row['handling_seconds'] / 60, 1),
            'handling_samples' => $row['handling_samples'],
            'transfers_out' => $row['transfers_out'],
            'pending_now' => $row['pending_now'],
            'overdue_now' => $row['overdue_now'],
            'sla_met' => $row['sla_met'],
            'sla_breached' => $row['sla_breached'],
            'sla_rate' => $row['sla_rate'] === null ? null : $row['sla_rate'] / 100,
        ];
    }

    /**
     * @return array<int, SourcedAgentRow>
     */
    private function agentRows(ReportContext $context): array
    {
        $key = spl_object_id($context);

        if (! isset($this->rows[$key])) {
            $this->rows[$key] = [];

            foreach ($this->productivity->rows($context->actor, $this->filters($context), withSourceIds: true) as $row) {
                $this->rows[$key][$row['source_id']] = $row;
            }
        }

        return $this->rows[$key];
    }

    private function filters(ReportContext $context): AnalyticsFilters
    {
        return $this->filters[spl_object_id($context)] ??= AnalyticsFilters::from($context->filters, $context->actor, $this->scope, $context->timezone, $context->asOf());
    }
}
