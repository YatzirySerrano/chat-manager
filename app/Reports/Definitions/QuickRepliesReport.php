<?php

namespace App\Reports\Definitions;

use App\Actions\QuickReplies\QuickReplyListQuery;
use App\Models\Department;
use App\Models\QuickReply;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Quick replies mirroring the quick replies screen: managers export every
 * reply within their scope, users that can only use replies export the
 * ones they can insert.
 *
 * @phpstan-import-type QuickReplyFilters from QuickReplyListQuery
 *
 * @extends ReportDefinition<QuickReply>
 */
class QuickRepliesReport extends ReportDefinition
{
    use DescribesReports;

    /**
     * Characters of the body kept in the report (the full text stays in the
     * platform).
     */
    private const BODY_LENGTH = 280;

    private const TOP_DEPARTMENTS = 10;

    public function __construct(private QuickReplyListQuery $list) {}

    public function key(): string
    {
        return 'quick-replies';
    }

    public function title(): string
    {
        return 'Respuestas rápidas';
    }

    public function subtitle(): ?string
    {
        return 'Textos predefinidos, su disponibilidad y autoría';
    }

    public function fileName(): string
    {
        return 'respuestas-rapidas';
    }

    public function orientation(): Orientation
    {
        return Orientation::Landscape;
    }

    public function authorize(User $actor): bool
    {
        return Gate::forUser($actor)->allows('viewAny', QuickReply::class);
    }

    public function rules(): array
    {
        return QuickReplyListQuery::rules();
    }

    public function describeFilters(ReportContext $context): array
    {
        $filters = $this->filters($context);
        $descriptions = [];

        $this->describeSearch($descriptions, $context);

        if ($filters['department'] !== null) {
            $descriptions[] = 'Departamento: '.(Department::query()->visibleTo($context->actor)->whereKey($filters['department'])->value('name') ?? 'No disponible');
        }

        if ($filters['availability'] !== null) {
            $descriptions[] = 'Disponibilidad: '.($filters['availability'] === 'global' ? 'Globales' : 'Por departamento');
        }

        $this->describeRecordState($descriptions, $filters['record']);

        if (! $filters['manages']) {
            $descriptions[] = 'Solo las respuestas que puedes usar';
        }

        return $descriptions;
    }

    public function query(ReportContext $context): Builder
    {
        return $this->baseQuery($context)->with(['creator:id,name', 'updater:id,name']);
    }

    public function count(ReportContext $context): int
    {
        return $this->aggregate($context)->count();
    }

    public function columns(): array
    {
        return [
            new Column('title', 'Título', width: 20),
            new Column('shortcut', 'Atajo', width: 13),
            new Column('availability', 'Disponible en', width: 22),
            new Column('status', 'Estado', width: 10),
            new Column('body', 'Texto', width: 50),
            new Column('created_by', 'Creada por', width: 16),
            new Column('updated_by', 'Actualizada por', width: 16),
            new Column('updated_at', 'Última actualización', ColumnType::DateTime, width: 16),
        ];
    }

    /**
     * @param  QuickReply  $record
     */
    public function map(Model $record, ReportContext $context): array
    {
        return [
            'title' => $record->title,
            'shortcut' => '/'.$record->shortcut,
            'availability' => $record->is_global ? 'Todos los departamentos' : ($record->departments->pluck('name')->implode(', ') ?: 'Sin departamentos'),
            'status' => $record->isDeleted() ? 'Eliminada' : 'Activa',
            'body' => Str::limit(trim((string) preg_replace('/\s+/u', ' ', $record->body)), self::BODY_LENGTH),
            'created_by' => $record->creator?->name,
            'updated_by' => $record->updater?->name,
            'updated_at' => $record->updated_at,
        ];
    }

    public function metrics(ReportContext $context): array
    {
        $total = $this->count($context);
        $global = $this->aggregate($context)->where('is_global', true)->count();
        $active = $this->aggregate($context)->where('is_active', true)->count();
        $metrics = [
            new Metric('Respuestas', $total, 'Con los filtros aplicados'),
            new Metric('Globales', $global, $this->shareOf($global, $total)),
            new Metric('Por departamento', $total - $global, $this->shareOf($total - $global, $total)),
        ];

        if ($context->actor->canViewDeletedRecords() && $this->filters($context)['manages']) {
            $metrics[] = new Metric('Eliminadas', $total - $active, 'Ya no se ofrecen al responder');
        }

        return $metrics;
    }

    public function charts(ReportContext $context): array
    {
        $total = $this->count($context);
        $global = $this->aggregate($context)->where('is_global', true)->count();
        $byDepartment = DB::table('department_quick_reply')
            ->join('departments', 'departments.id', '=', 'department_quick_reply.department_id')
            ->whereIn('department_quick_reply.quick_reply_id', $this->aggregate($context)->select('quick_replies.id'))
            ->when(! $context->actor->canViewDeletedRecords(), fn ($query) => $query->where('departments.is_active', true))
            ->selectRaw('departments.name AS label, COUNT(DISTINCT department_quick_reply.quick_reply_id) AS total')
            ->groupBy('departments.id', 'departments.name')
            ->orderByDesc('total')
            ->orderBy('departments.name')
            ->limit(self::TOP_DEPARTMENTS)
            ->pluck('total', 'label');

        return [
            new Chart(
                ChartType::Bar,
                'Respuestas por departamento',
                array_values(array_map('strval', $byDepartment->keys()->all())),
                [new ChartSeries('Respuestas', array_values(array_map('intval', $byDepartment->values()->all())))],
                'Respuestas compartidas con cada departamento (top '.self::TOP_DEPARTMENTS.')',
            ),
            new Chart(
                ChartType::Donut,
                'Disponibilidad',
                ['Globales', 'Por departamento'],
                [new ChartSeries('Respuestas', [$global, $total - $global])],
                'Respuestas para todos o solo para algunos departamentos',
            ),
        ];
    }

    public function pdfOptions(): PdfOptions
    {
        return new PdfOptions(tableTitle: 'Catálogo de respuestas rápidas');
    }

    /**
     * @return Builder<QuickReply>
     */
    private function baseQuery(ReportContext $context): Builder
    {
        return $this->list->handle($context->actor, $this->filters($context));
    }

    /**
     * The filtered replies without ordering or relations, for aggregates.
     *
     * @return Builder<QuickReply>
     */
    private function aggregate(ReportContext $context): Builder
    {
        return $this->baseQuery($context)->withoutEagerLoads()->reorder();
    }

    /**
     * @return QuickReplyFilters
     */
    private function filters(ReportContext $context): array
    {
        return $this->list->filters($context->actor, $context->filters);
    }
}
