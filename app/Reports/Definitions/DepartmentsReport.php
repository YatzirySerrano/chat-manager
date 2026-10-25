<?php

namespace App\Reports\Definitions;

use App\Actions\Departments\DepartmentListQuery;
use App\Enums\DistributionMode;
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
use App\Reports\Orientation;
use App\Reports\PdfOptions;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Departments with their team, routing and open workload, mirroring the
 * departments screen. Open conversations are counted only over the
 * conversations the actor can see.
 *
 * @phpstan-import-type DepartmentFilters from DepartmentListQuery
 *
 * @extends ReportDefinition<Department>
 */
class DepartmentsReport extends ReportDefinition
{
    use DescribesReports;

    private const TOP_DEPARTMENTS = 12;

    public function __construct(private DepartmentListQuery $list) {}

    public function key(): string
    {
        return 'departments';
    }

    public function title(): string
    {
        return 'Departamentos';
    }

    public function subtitle(): ?string
    {
        return 'Equipos, distribución de conversaciones y carga abierta';
    }

    public function fileName(): string
    {
        return 'departamentos';
    }

    public function orientation(): Orientation
    {
        return Orientation::Landscape;
    }

    public function authorize(User $actor): bool
    {
        return Gate::forUser($actor)->allows('viewAny', Department::class);
    }

    public function rules(): array
    {
        return DepartmentListQuery::rules();
    }

    public function describeFilters(ReportContext $context): array
    {
        $descriptions = [];
        $this->describeRecordState($descriptions, $this->filters($context)['record']);

        return $descriptions;
    }

    public function query(ReportContext $context): Builder
    {
        $actor = $context->actor;

        return $this->list->handle($actor, $this->filters($context))
            ->withCount(['conversations as open_conversations_count' => fn (Builder $conversations) => $this->openConversations($conversations, $actor)])
            ->addSelect(['default_connections_count' => Connection::query()
                ->selectRaw('COUNT(*)')
                ->whereColumn('connections.default_department_id', 'departments.id')
                ->hidingDeletedFrom($actor)]);
    }

    public function count(ReportContext $context): int
    {
        return $this->list->handle($context->actor, $this->filters($context))->withoutEagerLoads()->reorder()->count();
    }

    public function columns(): array
    {
        return [
            new Column('name', 'Departamento', width: 24),
            new Column('color', 'Color', width: 12),
            new Column('status', 'Estado', width: 11),
            new Column('leaders', 'Líderes', width: 24),
            new Column('members', 'Miembros', ColumnType::Number, width: 12, total: ColumnTotal::Sum),
            new Column('distribution', 'Distribución', width: 16),
            new Column('connections', 'Conexiones autorizadas', ColumnType::Number, width: 13, total: ColumnTotal::Sum),
            new Column('default_connections', 'Predeterminado en conexiones', ColumnType::Number, width: 14, total: ColumnTotal::Sum),
            new Column('open_conversations', 'Conversaciones abiertas', ColumnType::Number, width: 14, total: ColumnTotal::Sum),
            new Column('created_at', 'Fecha de creación', ColumnType::DateTime, width: 16),
        ];
    }

    /**
     * @param  Department  $record
     */
    public function map(Model $record, ReportContext $context): array
    {
        return [
            'name' => $record->name,
            'color' => $record->color->label(),
            'status' => $record->isDeleted() ? 'Eliminado' : 'Activo',
            'leaders' => $record->leaders->pluck('name')->implode(', ') ?: null,
            'members' => (int) $record->getAttribute('members_count'),
            'distribution' => $record->distribution_mode->label(),
            'connections' => (int) $record->getAttribute('connections_count'),
            'default_connections' => (int) $record->getAttribute('default_connections_count'),
            'open_conversations' => (int) $record->getAttribute('open_conversations_count'),
            'created_at' => $record->created_at,
        ];
    }

    public function metrics(ReportContext $context): array
    {
        $departments = $this->departments($context);
        $active = $departments->where('is_active', true)->count();
        $metrics = [
            new Metric('Departamentos', $departments->count(), 'Con los filtros aplicados'),
            new Metric('Activos', $active, $this->shareOf($active, $departments->count())),
        ];

        if ($context->actor->canViewDeletedRecords()) {
            $metrics[] = new Metric('Eliminados', $departments->count() - $active, 'Ya no reciben conversaciones');
        }

        $metrics[] = new Metric('Conversaciones abiertas', (int) $departments->sum(fn (Department $department): int => (int) $department->getAttribute('open_conversations_count')), 'Sin cerrar, visibles para ti');
        $metrics[] = new Metric('Miembros', (int) $departments->sum(fn (Department $department): int => (int) $department->getAttribute('members_count')), 'Suma de los equipos');

        return $metrics;
    }

    public function charts(ReportContext $context): array
    {
        $departments = $this->departments($context);
        $byLoad = $departments
            ->sortByDesc(fn (Department $department): int => (int) $department->getAttribute('open_conversations_count'))
            ->take(self::TOP_DEPARTMENTS)
            ->values();

        return [
            new Chart(
                ChartType::Bar,
                'Conversaciones abiertas por departamento',
                array_values($byLoad->map(fn (Department $department): string => $department->name)->all()),
                [new ChartSeries('Conversaciones abiertas', array_values($byLoad->map(fn (Department $department): int => (int) $department->getAttribute('open_conversations_count'))->all()))],
                'Pendientes y en atención (top '.self::TOP_DEPARTMENTS.')',
            ),
            new Chart(
                ChartType::Donut,
                'Modo de distribución',
                array_map(fn (DistributionMode $mode): string => $mode->label(), DistributionMode::cases()),
                [new ChartSeries('Departamentos', array_map(fn (DistributionMode $mode): int => $departments->filter(fn (Department $department): bool => $department->distribution_mode === $mode)->count(), DistributionMode::cases()))],
                'Cómo se reparten las conversaciones nuevas',
            ),
        ];
    }

    public function pdfOptions(): PdfOptions
    {
        return new PdfOptions(tableTitle: 'Detalle de departamentos');
    }

    /**
     * @param  Builder<Conversation>  $conversations
     */
    private function openConversations(Builder $conversations, User $actor): void
    {
        $conversations->notClosed()->visibleTo($actor);
    }

    /**
     * @return Collection<int, Department>
     */
    private function departments(ReportContext $context): Collection
    {
        return $this->query($context)->get();
    }

    /**
     * @return DepartmentFilters
     */
    private function filters(ReportContext $context): array
    {
        return $this->list->filters($context->actor, $context->filters);
    }
}
