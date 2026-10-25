<?php

namespace App\Reports\Definitions;

use App\Actions\Contacts\ContactListQuery;
use App\Enums\ConnectionChannel;
use App\Enums\ContactOriginType;
use App\Enums\ContactSource;
use App\Models\Contact;
use App\Models\Tag;
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
use App\Services\ResolveContactOrigin;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Gate;

/**
 * Contact directory mirroring the contacts screen: same filters, same data
 * scope (visibleTo) and the same record state rules. Like the screen, it
 * counts and exports only contacts saved into the directory unless the
 * "Incluir no guardados" filter is on (see ContactListQuery).
 *
 * @phpstan-import-type ContactFilters from ContactListQuery
 *
 * @extends ReportDefinition<Contact>
 */
class ContactsReport extends ReportDefinition
{
    use DescribesReports;

    /**
     * @var array<string, string>
     */
    private const SORT_LABELS = [
        'recent' => 'Interacción más reciente',
        'name' => 'Nombre',
        'newest' => 'Más nuevos',
    ];

    public function __construct(
        private ContactListQuery $list,
        private ResolveContactOrigin $resolveOrigin,
    ) {}

    public function key(): string
    {
        return 'contacts';
    }

    public function title(): string
    {
        return 'Contactos';
    }

    public function subtitle(): ?string
    {
        return 'Directorio de contactos, su origen e interacción';
    }

    public function fileName(): string
    {
        return 'contactos';
    }

    public function orientation(): Orientation
    {
        return Orientation::Landscape;
    }

    public function authorize(User $actor): bool
    {
        return Gate::forUser($actor)->allows('viewAny', Contact::class);
    }

    public function rules(): array
    {
        return ContactListQuery::rules();
    }

    public function describeFilters(ReportContext $context): array
    {
        $filters = $this->filters($context);
        $descriptions = [];

        $this->describeSearch($descriptions, $context);

        if ($filters['tags'] !== []) {
            $names = Tag::query()->whereKey($filters['tags'])->orderBy('name')->pluck('name')->all();
            $descriptions[] = 'Etiquetas: '.($names === [] ? 'sin coincidencias' : implode(', ', $names));
        }

        if ($filters['source'] !== null) {
            $descriptions[] = 'Fuente: '.(ContactSource::tryFrom($filters['source'])?->label() ?? $filters['source']);
        }

        if ($filters['origin'] !== null) {
            $descriptions[] = 'Origen: '.(ContactOriginType::tryFrom($filters['origin'])?->label() ?? $filters['origin']);
        }

        $this->describeRecordState($descriptions, $filters['record']);

        if ($filters['include_provisional']) {
            $descriptions[] = 'Incluye contactos no guardados en el directorio';
        }

        if ($filters['sort'] !== 'recent') {
            $descriptions[] = 'Orden: '.self::SORT_LABELS[$filters['sort']];
        }

        return $descriptions;
    }

    public function query(ReportContext $context): Builder
    {
        return $this->baseQuery($context)->with([
            'tags' => fn (BelongsToMany $query) => $query->select('tags.id', 'tags.name')->orderBy('tags.name'),
            'attributions',
        ]);
    }

    public function count(ReportContext $context): int
    {
        return $this->baseQuery($context)->count();
    }

    public function columns(): array
    {
        return [
            new Column('name', 'Nombre', width: 24),
            new Column('phone', 'Teléfono', width: 17, unbreakable: 29),
            new Column('email', 'Correo electrónico', width: 26),
            new Column('source', 'Fuente', width: 17),
            new Column('origin', 'Origen', width: 20),
            new Column('tags', 'Etiquetas', width: 22),
            new Column('first_interaction_at', 'Primera interacción', ColumnType::DateTime, width: 16),
            new Column('last_interaction_at', 'Última interacción', ColumnType::DateTime, width: 16),
            new Column('status', 'Estado', width: 11),
            new Column('created_at', 'Fecha de alta', ColumnType::DateTime, width: 16),
        ];
    }

    /**
     * @param  Contact  $record
     */
    public function map(Model $record, ReportContext $context): array
    {
        $origin = $this->resolveOrigin->best($record->attributions);

        return [
            'name' => $record->name,
            'phone' => PhoneNumber::format($record->phone_e164),
            'email' => $record->email,
            'source' => $record->source->label(),
            'origin' => ($origin->origin_type ?? ContactOriginType::Unknown)->label(),
            'tags' => $record->tags->pluck('name')->implode(', ') ?: null,
            'first_interaction_at' => $record->first_interaction_at,
            'last_interaction_at' => $record->last_interaction_at,
            'status' => match (true) {
                $record->isDeleted() => 'Eliminado',
                ! $record->isSaved() => 'No guardado',
                default => 'Activo',
            },
            'created_at' => $record->created_at,
        ];
    }

    public function metrics(ReportContext $context): array
    {
        $total = $this->count($context);
        $withWhatsapp = $this->aggregate($context)
            ->whereHas('identities', fn (Builder $identities) => $identities->where('channel', ConnectionChannel::Whatsapp))
            ->count();
        $origins = $this->originCounts($context);
        $identified = $total - ($origins[ContactOriginType::Unknown->label()] ?? 0);
        unset($origins[ContactOriginType::Unknown->label()]);
        arsort($origins);
        $leading = array_key_first(array_filter($origins, fn (int $count): bool => $count > 0));

        return [
            new Metric('Contactos', $total, 'En el directorio filtrado'),
            new Metric('Con WhatsApp', $withWhatsapp, $this->shareOf($withWhatsapp, $total)),
            new Metric('Con origen identificado', $identified, $this->shareOf($identified, $total)),
            new Metric(
                'Origen principal',
                $leading === null ? null : (string) $leading,
                $leading === null ? 'Ningún origen identificado' : number_format($origins[$leading]).' '.($origins[$leading] === 1 ? 'contacto' : 'contactos'),
            ),
        ];
    }

    public function charts(ReportContext $context): array
    {
        $origins = $this->topCategories($this->originCounts($context));
        $monthly = $this->monthlySeries($this->aggregate($context), $context);

        return [
            new Chart(
                ChartType::Donut,
                'Contactos por origen',
                $origins['labels'],
                [new ChartSeries('Contactos', $origins['values'])],
                'Origen más confiable registrado para cada contacto',
            ),
            new Chart(
                ChartType::Line,
                'Contactos nuevos por mes',
                $monthly['labels'],
                [new ChartSeries('Contactos nuevos', $monthly['values'])],
                'Altas de los últimos 12 meses con los filtros aplicados',
            ),
        ];
    }

    public function pdfOptions(): PdfOptions
    {
        return new PdfOptions(tableTitle: 'Directorio de contactos');
    }

    /**
     * Contacts per winning origin (label => count), over the filtered list.
     *
     * @return array<string, int>
     */
    private function originCounts(ReportContext $context): array
    {
        $counts = [];

        foreach (ContactOriginType::cases() as $origin) {
            $query = $this->aggregate($context);
            $this->resolveOrigin->constrainToOrigin($query, $origin === ContactOriginType::Unknown ? null : $origin->value);
            $counts[$origin->label()] = $query->count();
        }

        return $counts;
    }

    /**
     * @return Builder<Contact>
     */
    private function baseQuery(ReportContext $context): Builder
    {
        return $this->list->handle($context->actor, $this->filters($context));
    }

    /**
     * Filtered contacts without ordering or relations, for aggregates.
     *
     * @return Builder<Contact>
     */
    private function aggregate(ReportContext $context): Builder
    {
        return $this->baseQuery($context)->reorder();
    }

    /**
     * @return ContactFilters
     */
    private function filters(ReportContext $context): array
    {
        return $this->list->filters($context->actor, $context->filters);
    }
}
