<?php

namespace App\Reports\Definitions;

use App\Actions\QuickReplies\QuickReplyListQuery;
use App\Enums\DataScope;
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
 * Quick replies mirroring the quick replies screen. Without a segment (or
 * with "all") it is the general report: every reply the person may consult,
 * that is, corporate replies (managers: all within their scope; others: the
 * ones they can insert), their own personal replies and, only with the
 * supervision.view-personal permission, the personal replies of other users
 * of their scope. With the segment of a tab it keeps that tab and its
 * filters, and the document says so. Excel and PDF use the same query.
 * Departments outside the person's scope are never named.
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

        $descriptions[] = 'Tipo: '.match ($filters['segment']) {
            QuickReplyListQuery::SEGMENT_ALL => $this->generalScopeLabel($context->actor),
            QuickReplyListQuery::SEGMENT_MINE => 'Mis respuestas personales',
            QuickReplyListQuery::SEGMENT_OTHERS => 'Respuestas personales de otros usuarios',
            default => 'Corporativas',
        };

        $this->describeSearch($descriptions, $context);

        if ($filters['department'] !== null) {
            $descriptions[] = 'Departamento: '.(Department::query()->visibleTo($context->actor)->whereKey($filters['department'])->value('name') ?? 'No disponible');
        }

        if ($filters['availability'] !== null) {
            $descriptions[] = 'Disponibilidad: '.($filters['availability'] === 'global' ? 'Globales' : 'Por departamento');
        }

        $this->describeRecordState($descriptions, $filters['record']);

        if (! $filters['manages'] && $filters['segment'] === QuickReplyListQuery::SEGMENT_CORPORATE) {
            $descriptions[] = 'Solo las respuestas que puedes usar';
        }

        return $descriptions;
    }

    public function query(ReportContext $context): Builder
    {
        return $this->baseQuery($context)->with(['owner:id,name']);
    }

    public function count(ReportContext $context): int
    {
        return $this->aggregate($context)->count();
    }

    public function columns(): array
    {
        return [
            new Column('shortcut', 'Atajo', width: 14),
            new Column('title', 'Título', width: 18),
            new Column('body', 'Contenido', width: 46),
            new Column('type', 'Tipo', width: 11, unbreakable: 22),
            new Column('owner', 'Propietario', width: 16),
            new Column('department', 'Departamento', width: 18),
            new Column('status', 'Estado', width: 9, unbreakable: 18),
            new Column('created_at', 'Fecha de creación', ColumnType::DateTime, width: 15),
        ];
    }

    /**
     * Every exported reply is one the person may consult (the query decides
     * it), so its content is included.
     *
     * @param  QuickReply  $record
     */
    public function map(Model $record, ReportContext $context): array
    {
        return [
            'shortcut' => '/'.$record->shortcut,
            'title' => $record->title,
            'body' => Str::limit(trim((string) preg_replace('/\s+/u', ' ', $record->body)), self::BODY_LENGTH),
            'type' => $record->isPersonal() ? 'Personal' : 'Corporativa',
            'owner' => $record->isPersonal() ? $record->owner?->name : null,
            'department' => $this->departmentLabel($record, $context->actor),
            'status' => $record->isDeleted() ? 'Eliminada' : 'Activa',
            'created_at' => $record->created_at,
        ];
    }

    public function metrics(ReportContext $context): array
    {
        $actor = $context->actor;
        $segment = $this->filters($context)['segment'];
        $total = $this->count($context);
        $metrics = [new Metric('Respuestas', $total, 'Con los filtros aplicados')];

        if (in_array($segment, [QuickReplyListQuery::SEGMENT_ALL, QuickReplyListQuery::SEGMENT_CORPORATE], true)) {
            $global = $this->aggregate($context)->corporate()->where('is_global', true)->count();
            $byDepartment = $this->aggregate($context)->corporate()->where('is_global', false)->count();
            $metrics[] = new Metric('Corporativas globales', $global, $this->shareOf($global, $total));
            $metrics[] = new Metric('Corporativas por departamento', $byDepartment, $this->shareOf($byDepartment, $total));
        }

        if ($segment === QuickReplyListQuery::SEGMENT_ALL) {
            $mine = $this->aggregate($context)->personal($actor)->count();
            $metrics[] = new Metric('Mis respuestas', $mine, $this->shareOf($mine, $total));

            if (Gate::forUser($actor)->allows('superviseAny', QuickReply::class)) {
                $others = $this->aggregate($context)->personal()->where('owner_user_id', '!=', $actor->id)->count();
                $metrics[] = new Metric('De otros usuarios', $others, $this->shareOf($others, $total));
            }
        }

        if ($actor->canViewDeletedRecords() && $this->filters($context)['manages']) {
            $active = $this->aggregate($context)->where('is_active', true)->count();
            $metrics[] = new Metric('Eliminadas', $total - $active, 'Ya no se ofrecen al responder');
        }

        return $metrics;
    }

    public function charts(ReportContext $context): array
    {
        $actor = $context->actor;
        $global = $this->aggregate($context)->corporate()->where('is_global', true)->count();
        $byDepartmentTotal = $this->aggregate($context)->corporate()->where('is_global', false)->count();
        $mine = $this->aggregate($context)->personal($actor)->count();
        $others = $this->aggregate($context)->personal()->where('owner_user_id', '!=', $actor->id)->count();
        $reachable = $actor->dataScope() === DataScope::Global ? null : $actor->departmentIds();
        $byDepartment = DB::table('department_quick_reply')
            ->join('departments', 'departments.id', '=', 'department_quick_reply.department_id')
            ->whereIn('department_quick_reply.quick_reply_id', $this->aggregate($context)->select('quick_replies.id'))
            ->when(! $actor->canViewDeletedRecords(), fn ($query) => $query->where('departments.is_active', true))
            ->when($reachable !== null, fn ($query) => $query->whereIn('departments.id', $reachable))
            ->selectRaw('departments.name AS label, COUNT(DISTINCT department_quick_reply.quick_reply_id) AS total')
            ->groupBy('departments.id', 'departments.name')
            ->orderByDesc('total')
            ->orderBy('departments.name')
            ->limit(self::TOP_DEPARTMENTS)
            ->pluck('total', 'label');

        $types = array_filter([
            'Corporativas globales' => $global,
            'Corporativas por departamento' => $byDepartmentTotal,
            'Mis respuestas' => $mine,
            'De otros usuarios' => $others,
        ], fn (int $count): bool => $count > 0);

        return [
            new Chart(
                ChartType::Bar,
                'Respuestas corporativas por departamento',
                array_values(array_map('strval', $byDepartment->keys()->all())),
                [new ChartSeries('Respuestas', array_values(array_map('intval', $byDepartment->values()->all())))],
                'Respuestas compartidas con cada departamento (top '.self::TOP_DEPARTMENTS.')',
            ),
            new Chart(
                ChartType::Donut,
                'Tipo de respuesta',
                array_keys($types),
                [new ChartSeries('Respuestas', array_values($types))],
                'Corporativas y personales incluidas en el reporte',
            ),
        ];
    }

    public function pdfOptions(): PdfOptions
    {
        return new PdfOptions(tableTitle: 'Catálogo de respuestas rápidas');
    }

    /**
     * "Todos los departamentos" for global corporate replies, the names of
     * the reply's departments (corporate) or of the owner's departments
     * (personal), only those within the actor's scope.
     */
    private function departmentLabel(QuickReply $record, User $actor): ?string
    {
        if (! $record->isPersonal() && $record->is_global) {
            return 'Todos los departamentos';
        }

        $reachable = $actor->dataScope() === DataScope::Global ? null : $actor->departmentIds();
        $departments = $record->isPersonal()
            ? ($record->owner?->relationLoaded('departments') ? $record->owner->departments : collect())
            : $record->departments;

        $names = $departments
            ->filter(fn (Department $department): bool => $reachable === null || in_array($department->id, $reachable, true))
            ->pluck('name')
            ->implode(', ');

        if ($names !== '') {
            return $names;
        }

        return $record->isPersonal() ? null : 'Sin departamentos';
    }

    /**
     * What the general report includes for this person.
     */
    private function generalScopeLabel(User $actor): string
    {
        $parts = array_map(fn (string $segment): string => match ($segment) {
            QuickReplyListQuery::SEGMENT_MINE => 'mis respuestas',
            QuickReplyListQuery::SEGMENT_OTHERS => 'respuestas de otros usuarios',
            default => 'corporativas',
        }, $this->list->segmentsFor($actor));

        return 'Todas las respuestas a las que tienes acceso ('.implode(', ', $parts).')';
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
        return $this->list->reportFilters($context->actor, $context->filters);
    }
}
