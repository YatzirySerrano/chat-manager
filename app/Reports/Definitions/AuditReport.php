<?php

namespace App\Reports\Definitions;

use App\Actions\Audit\AuditLogQuery;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\User;
use App\Reports\Chart;
use App\Reports\ChartSeries;
use App\Reports\ChartType;
use App\Reports\Column;
use App\Reports\ColumnType;
use App\Reports\Concerns\DescribesReports;
use App\Reports\Metric;
use App\Reports\Orientation;
use App\Reports\PdfOptions;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Support\Audit\AuditPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * The audit log with the audit screen's filters and EXACTLY its visibility
 * (AuditLog::visibleTo): restricted entries only reach the root authority
 * and viewers without the global scope only export their own entries.
 *
 * Exporting requires audit.view and audit.export (not reports.export): the
 * audit trail is sensitive and is granted separately from module reports.
 * Properties were redacted when written; they are summarized here in plain
 * Spanish by the AuditPresenter, with the same redaction applied again.
 *
 * @phpstan-import-type AuditFilters from AuditLogQuery
 *
 * @extends ReportDefinition<AuditLog>
 */
class AuditReport extends ReportDefinition
{
    use DescribesReports;

    /**
     * Characters of the properties summary kept per entry.
     */
    private const SUMMARY_LENGTH = 400;

    private const TOP_ACTIONS = 8;

    public function __construct(
        private AuditLogQuery $audit,
        private AuditPresenter $presenter,
    ) {}

    public function key(): string
    {
        return 'audit';
    }

    public function title(): string
    {
        return 'Auditoría';
    }

    public function subtitle(): ?string
    {
        return 'Historial de operaciones administrativas';
    }

    public function fileName(): string
    {
        return 'auditoria';
    }

    public function orientation(): Orientation
    {
        return Orientation::Landscape;
    }

    public function authorize(User $actor): bool
    {
        return Gate::forUser($actor)->allows('viewAny', AuditLog::class);
    }

    public function exportPermission(): Permission
    {
        return Permission::ExportAuditLog;
    }

    public function rules(): array
    {
        return AuditLogQuery::rules();
    }

    public function describeFilters(ReportContext $context): array
    {
        $filters = $this->filters($context);
        $descriptions = [];

        if ($filters['action'] !== null) {
            $descriptions[] = 'Acción: '.(AuditAction::tryFrom($filters['action'])?->label() ?? $filters['action']);
        }

        if ($filters['actor'] !== null) {
            $name = $this->audit->handle($context->actor, $filters)->reorder()->value('actor_name');
            $descriptions[] = 'Realizada por: '.($name ?? 'Usuario #'.$filters['actor']);
        }

        if ($filters['from'] !== null) {
            $descriptions[] = 'Desde: '.CarbonImmutable::parse($filters['from'])->format('d/m/Y');
        }

        if ($filters['to'] !== null) {
            $descriptions[] = 'Hasta: '.CarbonImmutable::parse($filters['to'])->format('d/m/Y');
        }

        if ($context->actor->hasRootAuthority()) {
            $descriptions[] = 'Incluye registros restringidos (solo superadministración)';
        }

        return $descriptions;
    }

    public function query(ReportContext $context): Builder
    {
        return $this->audit->handle($context->actor, $this->filters($context));
    }

    public function count(ReportContext $context): int
    {
        return $this->aggregate($context)->count();
    }

    public function columns(): array
    {
        return [
            new Column('created_at', 'Fecha', ColumnType::DateTime, width: 15),
            new Column('actor', 'Realizada por', width: 18),
            new Column('action', 'Acción', width: 24),
            new Column('subject', 'Elemento', width: 26),
            new Column('details', 'Detalle', width: 46),
            new Column('ip', 'Dirección IP', width: 14, unbreakable: 30),
        ];
    }

    /**
     * @param  AuditLog  $record
     */
    public function map(Model $record, ReportContext $context): array
    {
        $type = $this->presenter->subjectTypeLabel($record->auditable_type);

        return [
            'created_at' => $record->created_at,
            'actor' => $record->actor_name ?? 'Sistema',
            'action' => $record->action->label(),
            'subject' => $type === null ? $record->auditable_label : $type.': '.($record->auditable_label ?? '#'.$record->auditable_id),
            'details' => $this->summarize($record),
            'ip' => $record->ip_address,
        ];
    }

    public function metrics(ReportContext $context): array
    {
        $total = $this->count($context);
        $actors = $this->aggregate($context)->whereNotNull('user_id')->distinct()->count('user_id');
        $top = $this->actionCounts($context, 1);
        $topAction = array_key_first($top);
        $metrics = [
            new Metric('Registros', $total, 'Con los filtros aplicados'),
            new Metric('Personas', $actors, 'Que realizaron operaciones'),
            new Metric(
                'Acción más frecuente',
                $topAction === null ? null : (string) $topAction,
                $topAction === null ? 'Sin registros' : number_format($top[$topAction]).' '.($top[$topAction] === 1 ? 'vez' : 'veces'),
            ),
        ];

        if ($context->actor->hasRootAuthority()) {
            $metrics[] = new Metric('Restringidos', $this->aggregate($context)->where('restricted', true)->count(), 'Solo visibles para superadministración');
        }

        return $metrics;
    }

    public function charts(ReportContext $context): array
    {
        $actions = $this->actionCounts($context, self::TOP_ACTIONS);
        $monthly = $this->monthlySeries($this->aggregate($context), $context);

        return [
            new Chart(
                ChartType::Bar,
                'Acciones más frecuentes',
                array_map('strval', array_keys($actions)),
                [new ChartSeries('Registros', array_values($actions))],
                'Top '.self::TOP_ACTIONS.' de los registros exportados',
            ),
            new Chart(
                ChartType::Line,
                'Actividad por mes',
                $monthly['labels'],
                [new ChartSeries('Registros', $monthly['values'])],
                'Registros de los últimos 12 meses con los filtros aplicados',
            ),
        ];
    }

    public function pdfOptions(): PdfOptions
    {
        return new PdfOptions(tableTitle: 'Registros de auditoría');
    }

    /**
     * The entry's changes and details in plain Spanish (AuditPresenter, which
     * redacts the properties again), truncated. The export requires
     * audit.export, so identifiers and Meta codes are included.
     */
    private function summarize(AuditLog $record): ?string
    {
        $lines = $this->presenter->lines($record, includeTechnical: true);

        return $lines === [] ? null : Str::limit(implode(' · ', $lines), self::SUMMARY_LENGTH);
    }

    /**
     * Entries per action label, most frequent first.
     *
     * @return array<string, int>
     */
    private function actionCounts(ReportContext $context, int $limit): array
    {
        $counts = $this->aggregate($context)
            ->toBase()
            ->selectRaw('action, COUNT(*) AS total')
            ->groupBy('action')
            ->orderByDesc('total')
            ->orderBy('action')
            ->limit($limit)
            ->pluck('total', 'action');

        $result = [];

        foreach ($counts as $action => $total) {
            $result[AuditAction::tryFrom((string) $action)?->label() ?? (string) $action] = (int) $total;
        }

        return $result;
    }

    /**
     * @return Builder<AuditLog>
     */
    private function aggregate(ReportContext $context): Builder
    {
        return $this->audit->handle($context->actor, $this->filters($context))->reorder();
    }

    /**
     * @return AuditFilters
     */
    private function filters(ReportContext $context): array
    {
        return $this->audit->filters($context->filters);
    }
}
