<?php

namespace App\Reports\Definitions;

use App\Actions\Tags\TagCatalogQuery;
use App\Enums\RecordState;
use App\Models\Tag;
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
use Illuminate\Validation\Rule;

/**
 * Tag catalog with its usage, mirroring the tags screen (same filters,
 * same visibility of the counted contacts and conversations; only contacts
 * saved into the directory are counted, see TagCatalogQuery).
 *
 * @extends ReportDefinition<Tag>
 */
class TagsReport extends ReportDefinition
{
    use DescribesReports;

    private const TOP_TAGS = 10;

    public function __construct(private TagCatalogQuery $catalog) {}

    public function key(): string
    {
        return 'tags';
    }

    public function title(): string
    {
        return 'Etiquetas';
    }

    public function subtitle(): ?string
    {
        return 'Catálogo de etiquetas y su uso en contactos y conversaciones';
    }

    public function orientation(): Orientation
    {
        return Orientation::Landscape;
    }

    public function fileName(): string
    {
        return 'etiquetas';
    }

    public function authorize(User $actor): bool
    {
        return Gate::forUser($actor)->allows('viewAny', Tag::class);
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'record' => ['nullable', Rule::enum(RecordState::class)],
        ];
    }

    public function describeFilters(ReportContext $context): array
    {
        $descriptions = [];
        $record = $this->recordState($context);

        $this->describeSearch($descriptions, $context);

        if ($record !== RecordState::Active) {
            $descriptions[] = 'Estado del registro: '.($record === RecordState::Deleted ? 'Eliminadas' : 'Todas');
        }

        return $descriptions;
    }

    public function query(ReportContext $context): Builder
    {
        return $this->catalog->handle($context->actor, $this->catalogFilters($context))
            ->with('creator:id,name');
    }

    public function count(ReportContext $context): int
    {
        return $this->baseQuery($context)->count();
    }

    public function columns(): array
    {
        return [
            new Column('name', 'Nombre', width: 26),
            new Column('slug', 'Identificador', width: 22),
            new Column('color', 'Color', width: 12),
            new Column('description', 'Descripción', width: 40),
            new Column('status', 'Estado', width: 11),
            new Column('contacts', 'Contactos', ColumnType::Number, width: 11, total: ColumnTotal::Sum),
            new Column('conversations', 'Conversaciones', ColumnType::Number, width: 16, total: ColumnTotal::Sum),
            new Column('created_by', 'Creada por', width: 20),
            new Column('created_at', 'Fecha de creación', ColumnType::DateTime, width: 18),
        ];
    }

    /**
     * @param  Tag  $record
     */
    public function map(Model $record, ReportContext $context): array
    {
        return [
            'name' => $record->name,
            'slug' => $record->slug,
            'color' => $record->color->label(),
            'description' => $record->description,
            'status' => $record->isDeleted() ? 'Eliminada' : 'Activa',
            'contacts' => (int) $record->getAttribute('contacts_count'),
            'conversations' => (int) $record->getAttribute('conversations_count'),
            'created_by' => $record->creator?->name,
            'created_at' => $record->created_at,
        ];
    }

    public function metrics(ReportContext $context): array
    {
        $total = $this->baseQuery($context)->count();
        $active = $this->baseQuery($context)->where('is_active', true)->count();
        $mostUsed = $this->mostUsed($context, 1)->first();
        $mostUsedContacts = (int) $mostUsed?->getAttribute('contacts_count');
        $metrics = [
            new Metric('Etiquetas', $total, 'En el catálogo filtrado'),
            new Metric('Activas', $active, $total > 0 ? number_format($active / $total * 100, 1).' % del total' : null),
        ];

        // Deleted tags only exist for people who can consult deleted records.
        if ($context->actor->canViewDeletedRecords()) {
            $metrics[] = new Metric('Eliminadas', $total - $active, 'No se pueden asignar de nuevo');
        }

        $metrics[] = new Metric(
            'Más usada',
            $mostUsed !== null && $mostUsedContacts > 0 ? $mostUsed->name : null,
            $mostUsed !== null && $mostUsedContacts > 0 ? number_format($mostUsedContacts).' '.($mostUsedContacts === 1 ? 'contacto' : 'contactos') : 'Ninguna etiqueta en uso',
        );

        return $metrics;
    }

    public function charts(ReportContext $context): array
    {
        $top = $this->mostUsed($context, self::TOP_TAGS)
            ->filter(fn (Tag $tag): bool => (int) $tag->getAttribute('contacts_count') > 0)
            ->values();
        $charts = [
            new Chart(
                ChartType::Bar,
                'Etiquetas más usadas',
                array_values($top->map(fn (Tag $tag): string => $tag->name)->all()),
                [new ChartSeries('Contactos', array_values($top->map(fn (Tag $tag): int => (int) $tag->getAttribute('contacts_count'))->all()))],
                'Contactos visibles para ti con cada etiqueta (top '.self::TOP_TAGS.')',
            ),
        ];

        if ($context->actor->canViewDeletedRecords()) {
            $total = $this->baseQuery($context)->count();
            $active = $this->baseQuery($context)->where('is_active', true)->count();
            $charts[] = new Chart(
                ChartType::Donut,
                'Estado del catálogo',
                ['Activas', 'Eliminadas'],
                [new ChartSeries('Etiquetas', [$active, $total - $active])],
                'Etiquetas activas y eliminadas',
            );
        }

        return $charts;
    }

    public function pdfOptions(): PdfOptions
    {
        return new PdfOptions(tableTitle: 'Catálogo de etiquetas');
    }

    /**
     * Filtered tags without usage counts or ordering, for aggregates.
     *
     * @return Builder<Tag>
     */
    private function baseQuery(ReportContext $context): Builder
    {
        return $this->catalog->handle($context->actor, $this->catalogFilters($context))
            ->withoutEagerLoads()
            ->reorder()
            ->select('tags.*');
    }

    /**
     * @return Collection<int, Tag>
     */
    private function mostUsed(ReportContext $context, int $limit): Collection
    {
        return $this->catalog->handle($context->actor, $this->catalogFilters($context))
            ->reorder()
            ->orderByDesc('contacts_count')
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    /**
     * @return array{search: string|null, record: string}
     */
    private function catalogFilters(ReportContext $context): array
    {
        $search = $context->filter('search');

        return [
            'search' => is_string($search) ? $search : null,
            'record' => $this->recordState($context)->value,
        ];
    }

    /**
     * The record state the actor may export (always active without
     * records.view-deleted, whatever the query string asks).
     */
    private function recordState(ReportContext $context): RecordState
    {
        return RecordState::resolveFor($context->actor, $context->filter('record'));
    }
}
