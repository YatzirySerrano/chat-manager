<?php

namespace App\Reports\Definitions;

use App\Enums\ConversationOutcome;
use App\Enums\Permission;
use App\Models\Conversation;
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
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\AnalyticsScope;
use App\Services\Analytics\RequestOutcomes;
use App\Support\ProtectedAccounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * «Resultados de solicitudes»: the requests completed in the period and
 * their credit result, the same figures, filters and reach as the
 * «Resultados» view of /reports (both read RequestOutcomes). Totals, one
 * table per collaborator (who completed the request: approvals and the
 * authorized amount are what commissions), per department and per day,
 * and the detail of every request.
 *
 * @phpstan-import-type PersonRow from RequestOutcomes
 * @phpstan-import-type DepartmentRow from RequestOutcomes
 *
 * @extends ReportDefinition<Conversation>
 */
class RequestOutcomesReport extends ReportDefinition
{
    use DescribesAnalytics;

    /**
     * @var array<int, AnalyticsFilters>
     */
    private array $filters = [];

    /**
     * Names of the people who completed requests, read once each.
     *
     * @var array<int, string>
     */
    private array $names = [];

    public function __construct(
        private RequestOutcomes $outcomes,
        private AnalyticsScope $scope,
        private ProtectedAccounts $protectedAccounts,
    ) {}

    public function key(): string
    {
        return 'request-outcomes';
    }

    public function title(): string
    {
        return 'Resultados de solicitudes';
    }

    public function subtitle(): ?string
    {
        return 'Solicitudes completadas: créditos aprobados, rechazadas y monto autorizado';
    }

    public function fileName(): string
    {
        return 'resultados-de-solicitudes';
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
        return [
            ...$this->describeAnalyticsFilters($this->filters($context), $context->actor, $this->scope->label($context->actor), withComparison: false),
            'Fecha de cada solicitud: cuando se registró su resultado, o cuando se completó si no tiene',
        ];
    }

    /**
     * Every completed request of the period, newest result first.
     */
    public function query(ReportContext $context): Builder
    {
        $query = Conversation::query()
            ->select('conversations.*')
            ->with(['contact:id,name', 'department:id,name']);
        $this->outcomes->constrain($query->getQuery(), $context->actor, $this->filters($context));

        return $query->orderByRaw(RequestOutcomes::DATE.' DESC')->orderByDesc('conversations.id');
    }

    public function columns(): array
    {
        return [
            new Column('date', 'Fecha del resultado', ColumnType::DateTime, width: 16),
            new Column('id', 'Folio', ColumnType::Number, width: 8),
            new Column('contact', 'Cliente', width: 22),
            new Column('agent', 'Completó', width: 20),
            new Column('department', 'Departamento', width: 16),
            new Column('outcome', 'Resultado', width: 16, total: ColumnTotal::Count),
            new Column('amount', 'Monto autorizado', ColumnType::Currency, width: 15, total: ColumnTotal::Sum),
        ];
    }

    /**
     * @param  Conversation  $record
     */
    public function map(Model $record, ReportContext $context): array
    {
        return [
            'date' => $record->outcome_at ?? $record->completed_at,
            'id' => $record->id,
            'contact' => $record->contact->name ?? '',
            'agent' => $this->agentName($record->closed_by_user_id, $context->actor),
            'department' => $record->department->name ?? RequestOutcomes::WITHOUT_DEPARTMENT,
            'outcome' => $record->outcome?->label() ?? 'Sin resultado',
            'amount' => $record->outcome === ConversationOutcome::Approved && $record->outcome_amount !== null ? (float) $record->outcome_amount : null,
        ];
    }

    public function metrics(ReportContext $context): array
    {
        $totals = $this->outcomes->totals($context->actor, $this->filters($context));

        return [
            new Metric('Solicitudes completadas', $totals['completed'], 'Atendidas y cerradas como «Solicitud completada»'),
            new Metric('Créditos aprobados', $totals['approved'], 'Lo único que comisiona'),
            new Metric('Rechazadas', $totals['rejected'], 'Atendidas, pero el crédito no se aprobó'),
            new Metric('Sin resultado', $totals['without'], 'Completadas sin registrar si se aprobaron'),
            new Metric(
                'Tasa de aprobación',
                $this->spellRate($totals['approval_rate']),
                $totals['approval_rate'] === null ? 'Sin datos: ninguna solicitud con resultado' : 'Aprobadas de las que tienen resultado (n = '.number_format($totals['approved'] + $totals['rejected']).')',
                ColumnType::Text,
            ),
            new Metric('Monto autorizado', $totals['amount'], 'Suma de los créditos aprobados', ColumnType::Currency),
            new Metric(
                'Monto promedio por crédito',
                $totals['average_amount'],
                $totals['average_amount'] === null ? 'Sin datos: ningún crédito aprobado con monto' : 'De '.number_format($totals['with_amount']).' créditos aprobados con monto',
                ColumnType::Currency,
            ),
        ];
    }

    public function charts(ReportContext $context): array
    {
        $top = array_slice($this->outcomes->byAgent($context->actor, $this->filters($context)), 0, 10);

        if ($top === []) {
            return [];
        }

        return [
            new Chart(
                ChartType::Bar,
                'Resultados por colaborador',
                array_column($top, 'name'),
                [
                    new ChartSeries('Aprobados', array_column($top, 'approved')),
                    new ChartSeries('Rechazadas', array_column($top, 'rejected')),
                ],
                'Los 10 colaboradores con más monto autorizado',
            ),
        ];
    }

    public function extraSheets(ReportContext $context): array
    {
        $filters = $this->filters($context);
        $trend = $this->outcomes->trend($context->actor, $filters);

        return [
            new ReportSheet(
                'Por colaborador',
                self::figureColumns('Colaborador'),
                array_map(fn (array $row): array => self::figureRow($row), $this->outcomes->byAgent($context->actor, $filters)),
                inPdf: true,
                beforeTable: true,
                description: 'Quién completó cada solicitud: los créditos aprobados y su monto son la base de la comisión.',
            ),
            new ReportSheet(
                'Por departamento',
                self::figureColumns('Departamento'),
                array_map(fn (array $row): array => self::figureRow($row), $this->outcomes->byDepartment($context->actor, $filters)),
                inPdf: true,
                beforeTable: true,
                description: 'Departamento actual de cada solicitud.',
            ),
            new ReportSheet(
                'Por '.$this->bucketUnit($trend['granularity']),
                self::figureColumns(ucfirst($this->bucketUnit($trend['granularity']))),
                array_map(fn (array $point): array => self::figureRow(['name' => $point['label'], ...$point]), $trend['points']),
                description: 'Por la fecha del resultado (o de la completación sin resultado).',
            ),
        ];
    }

    public function pdfOptions(): PdfOptions
    {
        return new PdfOptions(tableTitle: 'Detalle de solicitudes completadas');
    }

    /**
     * Columns of a figures table (per person, department or bucket).
     *
     * @return list<Column>
     */
    public static function figureColumns(string $label): array
    {
        return [
            new Column('name', $label, width: 24),
            new Column('completed', 'Completadas', ColumnType::Number, width: 11, total: ColumnTotal::Sum),
            new Column('approved', 'Créditos aprobados', ColumnType::Number, width: 11, total: ColumnTotal::Sum),
            new Column('rejected', 'Rechazadas', ColumnType::Number, width: 11, total: ColumnTotal::Sum),
            new Column('without', 'Sin resultado', ColumnType::Number, width: 11, total: ColumnTotal::Sum),
            new Column('approval_rate', 'Tasa de aprobación', ColumnType::Percent, width: 11),
            new Column('amount', 'Monto autorizado', ColumnType::Currency, width: 15, total: ColumnTotal::Sum),
            new Column('average_amount', 'Monto promedio', ColumnType::Currency, width: 14),
        ];
    }

    /**
     * @param  array<string, mixed>  $row  A row of RequestOutcomes (figures and a name).
     * @return array<string, string|int|float|null>
     */
    public static function figureRow(array $row): array
    {
        return [
            'name' => (string) $row['name'],
            'completed' => (int) $row['completed'],
            'approved' => (int) $row['approved'],
            'rejected' => (int) $row['rejected'],
            'without' => (int) $row['without'],
            'approval_rate' => $row['approval_rate'] === null ? null : (float) $row['approval_rate'] / 100,
            'amount' => (float) $row['amount'],
            'average_amount' => $row['average_amount'] === null ? null : (float) $row['average_amount'],
        ];
    }

    private function agentName(?int $userId, User $viewer): string
    {
        if ($userId === null) {
            return RequestOutcomes::WITHOUT_PERSON;
        }

        if (! $viewer->hasRootAuthority() && in_array($userId, $this->protectedAccounts->ids(), true)) {
            return ProtectedAccounts::SYSTEM_NAME;
        }

        return $this->names[$userId] ??= (string) (User::query()->whereKey($userId)->value('name') ?? RequestOutcomes::WITHOUT_PERSON);
    }

    private function filters(ReportContext $context): AnalyticsFilters
    {
        return $this->filters[spl_object_id($context)] ??= AnalyticsFilters::from($context->filters, $context->actor, $this->scope, $context->timezone, $context->asOf());
    }
}
