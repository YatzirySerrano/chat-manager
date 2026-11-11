<?php

namespace App\Reports\Definitions;

use App\Enums\Permission;
use App\Models\ConversationCategory;
use App\Models\Department;
use App\Models\ResponseCycle;
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
use App\Services\Analytics\ServiceLevelAnalytics;
use App\Support\ProtectedAccounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Service level compliance with the detail of every breach: the same
 * figures, filters and reach as the SLA panels of the dashboard and the
 * reports (ServiceLevelAnalytics), and one row per response cycle whose
 * deadline passed as main table.
 *
 * @phpstan-import-type Compliance from ServiceLevelAnalytics
 *
 * @extends ReportDefinition<ResponseCycle>
 */
class SlaComplianceReport extends ReportDefinition
{
    use DescribesAnalytics;

    /**
     * @var array<int, Compliance>
     */
    private array $compliance = [];

    /**
     * @var array<int, AnalyticsFilters>
     */
    private array $filters = [];

    /**
     * Names resolved once per export.
     *
     * @var array<string, array<int, string>>
     */
    private array $names = [];

    public function __construct(
        private ServiceLevelAnalytics $serviceLevels,
        private AgentProductivity $productivity,
        private AnalyticsScope $scope,
        private ProtectedAccounts $protectedAccounts,
    ) {}

    public function key(): string
    {
        return 'sla-compliance';
    }

    public function title(): string
    {
        return 'Cumplimiento de SLA';
    }

    public function subtitle(): ?string
    {
        return 'Ciclos de respuesta iniciados en el periodo y detalle de incumplimientos';
    }

    public function fileName(): string
    {
        return 'cumplimiento-de-sla';
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
        $descriptions = $this->describeAnalyticsFilters($filters->withoutStatus(), $context->actor, $this->scope->label($context->actor));

        if ($filters->status !== null) {
            $descriptions[] = 'El filtro de estado no se aplica a los ciclos de respuesta';
        }

        return $descriptions;
    }

    /**
     * Breached cycles of the period, most recent first.
     */
    public function query(ReportContext $context): Builder
    {
        return ResponseCycle::query()->setQuery($this->serviceLevels->breaches($context->actor, $this->filters($context)));
    }

    public function columns(): array
    {
        return [
            new Column('conversation', 'Solicitud', ColumnType::Number, width: 10),
            new Column('kind', 'Tipo de respuesta', width: 18),
            new Column('department', 'Departamento', width: 20),
            new Column('category', 'Categoría', width: 18),
            new Column('responsible', 'Responsable al vencer', width: 22),
            new Column('started_at', 'Mensaje del cliente', ColumnType::DateTime, width: 17),
            new Column('due_at', 'Vencimiento', ColumnType::DateTime, width: 17),
            new Column('responded_at', 'Respuesta', ColumnType::DateTime, width: 17),
            new Column('target', 'Objetivo (min)', ColumnType::Number, width: 11, decimals: 1),
            new Column('exceeded', 'Exceso (min)', ColumnType::Number, width: 11, decimals: 1),
            new Column('state', 'Situación', width: 20),
            // Unit of the objective and of the overrun of the row.
            new Column('clock', 'Plazo contado en', width: 16),
        ];
    }

    /**
     * @param  ResponseCycle  $record
     */
    public function map(Model $record, ReportContext $context): array
    {
        $responsible = $record->breached_at === null ? $record->assigned_user_id : $record->breach_user_id;

        return [
            'conversation' => $record->conversation_id,
            'kind' => $record->kind->label(),
            'department' => $record->department_id === null ? 'Sin departamento' : ($this->names('departments', $context)[$record->department_id] ?? 'Sin departamento'),
            'category' => $record->category_id === null ? 'Sin categoría' : ($this->names('categories', $context)[$record->category_id] ?? 'Sin categoría'),
            'responsible' => $responsible === null ? 'En cola (sin responsable)' : ($this->names('users', $context)[$responsible] ?? 'Usuario no disponible'),
            'started_at' => $record->started_at,
            'due_at' => $record->due_at,
            'responded_at' => $record->responded_at,
            'target' => $this->toMinutes($record->target_seconds),
            'exceeded' => $this->toMinutes($record->exceeded_seconds),
            'state' => match (true) {
                $record->outcome === ResponseCycle::OUTCOME_ANSWERED => 'Respondida tarde',
                $record->outcome === ResponseCycle::OUTCOME_CLOSED => 'Cerrada sin respuesta',
                default => 'Sigue sin respuesta',
            },
            'clock' => $record->business_hours_only ? 'Tiempo hábil' : 'Tiempo corrido',
        ];
    }

    public function metrics(ReportContext $context): array
    {
        $sla = $this->report($context);
        $totals = $sla['totals'];
        $times = $sla['response_time'];
        $note = match (true) {
            $sla['available_since'] === null => 'El seguimiento por ciclo aún no tiene registros.',
            ! $sla['covers_period'] => "Disponible desde {$sla['available_since']}: el periodo empieza antes y la cifra es parcial.",
            default => "Registros disponibles desde {$sla['available_since']}.",
        };

        return [
            new Metric(
                'Cumplimiento de SLA',
                $this->spellRate($totals['rate']),
                $totals['samples'] === 0 ? "Sin datos: ningún ciclo con objetivo evaluado. {$note}" : 'n = '.number_format($totals['samples'])." ciclos evaluados. {$note}",
                ColumnType::Text,
            ),
            new Metric('Dentro del plazo', $totals['met'], 'Respondidos antes de su vencimiento'),
            new Metric('Fuera del plazo', $totals['breached'], 'Vencieron: respondidos tarde, cerrados tarde o aún sin respuesta'),
            new Metric('En curso', $totals['in_progress'], 'Abiertos dentro de su plazo: todavía no se evalúan'),
            new Metric('Cerrados sin respuesta a tiempo', $totals['closed_unanswered'], 'Se cerraron antes del vencimiento: ni cumplen ni incumplen'),
            new Metric('Sin objetivo', $totals['unmeasured'], 'Ciclos sin tiempo máximo configurado: fuera del cumplimiento'),
            new Metric(
                'Tiempo de respuesta por mensaje del cliente (promedio)',
                $this->spellDuration($times['avg']),
                $times['samples'] === 0 ? 'Sin ciclos respondidos' : 'Mínimo '.$this->spellDuration($times['min']).' · máximo '.$this->spellDuration($times['max']).' · n = '.number_format($times['samples']),
            ),
            new Metric('Cumplimiento del periodo de comparación', $this->spellRate($sla['previous']['rate']), 'n = '.number_format($sla['previous']['samples']), ColumnType::Text),
        ];
    }

    public function charts(ReportContext $context): array
    {
        $sla = $this->report($context);
        $charts = [];

        if ($sla['totals']['samples'] > 0) {
            $charts[] = new Chart(
                ChartType::Donut,
                'Ciclos evaluados',
                ['Dentro del plazo', 'Fuera del plazo'],
                [new ChartSeries('Ciclos', [$sla['totals']['met'], $sla['totals']['breached']])],
                'n = '.number_format($sla['totals']['samples']),
                [ReportPalette::status('closed'), ReportPalette::status('failed')],
            );
            $charts[] = new Chart(
                ChartType::Line,
                'Cumplimiento por '.$this->bucketUnit($sla['trend']['granularity']),
                array_column($sla['trend']['points'], 'label'),
                [new ChartSeries('Cumplimiento (%)', array_column($sla['trend']['points'], 'rate'))],
                'Los huecos son periodos sin ciclos evaluados',
            );
            $top = array_slice($sla['by_department'], 0, 10);
            $charts[] = new Chart(
                ChartType::Bar,
                'Retrasos por departamento',
                array_column($top, 'name'),
                [new ChartSeries('Fuera del plazo', array_column($top, 'breached'))],
                'Departamento que tenía la solicitud al iniciar el ciclo',
            );
        }

        return $charts;
    }

    public function extraSheets(ReportContext $context): array
    {
        $sla = $this->report($context);
        $group = fn (string $dimension): array => [
            new Column('name', $dimension, width: 24),
            new Column('met', 'Dentro del plazo', ColumnType::Number, width: 14, total: ColumnTotal::Sum),
            new Column('breached', 'Fuera del plazo', ColumnType::Number, width: 14, total: ColumnTotal::Sum),
            new Column('in_progress', 'En curso', ColumnType::Number, width: 10, total: ColumnTotal::Sum),
            new Column('samples', 'Ciclos evaluados (n)', ColumnType::Number, width: 16, total: ColumnTotal::Sum),
            new Column('rate', 'Cumplimiento', ColumnType::Percent, width: 13),
        ];
        $rows = fn (array $items): array => array_map(fn (array $row): array => [
            'name' => $row['name'],
            'met' => $row['met'],
            'breached' => $row['breached'],
            'in_progress' => $row['in_progress'] ?? null,
            'samples' => $row['samples'],
            'rate' => $row['rate'] === null ? null : $row['rate'] / 100,
        ], $items);
        $agents = array_values(array_filter(
            $this->productivity->rows($context->actor, $this->filters($context)->withoutStatus()),
            fn (array $row): bool => $row['sla_samples'] > 0 || $row['sla_late_answers'] > 0,
        ));

        return [
            new ReportSheet('Por tipo', $group('Tipo de respuesta'), $rows(array_map(fn (array $kind): array => [...$kind, 'name' => $kind['label']], $sla['kinds'])), inPdf: true, beforeTable: true, description: 'Primera respuesta de la solicitud y respuestas a los mensajes posteriores del cliente.'),
            new ReportSheet('Por departamento', $group('Departamento'), $rows($sla['by_department']), inPdf: true, beforeTable: true, description: 'Departamento que tenía la solicitud al iniciar cada ciclo.'),
            new ReportSheet('Por categoría', $group('Categoría'), $rows($sla['by_category']), description: 'Categoría de la solicitud al iniciar cada ciclo.'),
            new ReportSheet(
                'Por colaborador',
                [
                    new Column('name', 'Colaborador', width: 24),
                    new Column('met', 'Respondió dentro del plazo', ColumnType::Number, width: 16, total: ColumnTotal::Sum),
                    new Column('breached', 'Vencieron a su cargo', ColumnType::Number, width: 16, total: ColumnTotal::Sum),
                    new Column('late', 'Respondió tarde', ColumnType::Number, width: 14, total: ColumnTotal::Sum),
                    new Column('samples', 'Ciclos evaluados (n)', ColumnType::Number, width: 16, total: ColumnTotal::Sum),
                    new Column('rate', 'Cumplimiento', ColumnType::Percent, width: 13),
                ],
                array_map(fn (array $row): array => [
                    'name' => $row['name'],
                    'met' => $row['sla_met'],
                    'breached' => $row['sla_breached'],
                    'late' => $row['sla_late_answers'],
                    'samples' => $row['sla_samples'],
                    'rate' => $row['sla_rate'] === null ? null : $row['sla_rate'] / 100,
                ], $agents),
                inPdf: true,
                beforeTable: true,
                description: 'Un ciclo cumplido cuenta para quien respondió; uno vencido, para quien era responsable al vencer (los vencidos en cola no aparecen aquí). «Respondió tarde» es informativo.',
            ),
            new ReportSheet(
                'Tendencia',
                [
                    new Column('label', ucfirst($this->bucketUnit($sla['trend']['granularity'])), width: 14),
                    new Column('met', 'Dentro del plazo', ColumnType::Number, width: 14, total: ColumnTotal::Sum),
                    new Column('breached', 'Fuera del plazo', ColumnType::Number, width: 14, total: ColumnTotal::Sum),
                    new Column('rate', 'Cumplimiento', ColumnType::Percent, width: 13),
                ],
                array_map(fn (array $point): array => [...$point, 'rate' => $point['rate'] === null ? null : $point['rate'] / 100], $sla['trend']['points']),
                description: 'Por la fecha en que inició cada ciclo.',
            ),
        ];
    }

    public function pdfOptions(): PdfOptions
    {
        return new PdfOptions(tableTitle: 'Detalle de incumplimientos');
    }

    /**
     * @param  'departments'|'categories'|'users'  $kind
     * @return array<int, string>
     */
    private function names(string $kind, ReportContext $context): array
    {
        return $this->names[$kind] ??= match ($kind) {
            'departments' => Department::query()->pluck('name', 'id')->all(),
            'categories' => ConversationCategory::query()->pluck('name', 'id')->all(),
            'users' => User::query()->pluck('name', 'id')
                ->map(fn (string $name, int $id): string => ! $context->actor->hasRootAuthority() && in_array($id, $this->protectedAccounts->ids(), true) ? ProtectedAccounts::SYSTEM_NAME : $name)
                ->all(),
        };
    }

    /**
     * @return Compliance
     */
    private function report(ReportContext $context): array
    {
        return $this->compliance[spl_object_id($context)] ??= $this->serviceLevels->compliance($context->actor, $this->filters($context));
    }

    private function filters(ReportContext $context): AnalyticsFilters
    {
        return $this->filters[spl_object_id($context)] ??= AnalyticsFilters::from($context->filters, $context->actor, $this->scope, $context->timezone, $context->asOf());
    }
}
