<?php

namespace App\Reports\Definitions;

use App\Actions\Templates\MessageTemplateListQuery;
use App\Enums\MessageTemplateCategory;
use App\Enums\MessageTemplateStatus;
use App\Enums\RecordState;
use App\Models\Connection;
use App\Models\MessageTemplate;
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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * WhatsApp templates as synchronized from Meta, mirroring the templates
 * screen: status, category, language, quality and a text preview. No
 * prices: Meta's category is exported as reported, never as an amount.
 *
 * @extends ReportDefinition<MessageTemplate>
 */
class MessageTemplatesReport extends ReportDefinition
{
    use DescribesReports;

    public function __construct(private MessageTemplateListQuery $list) {}

    public function key(): string
    {
        return 'message-templates';
    }

    public function title(): string
    {
        return 'Plantillas de WhatsApp';
    }

    public function subtitle(): ?string
    {
        return 'Plantillas traídas de Meta y su estado de revisión';
    }

    public function fileName(): string
    {
        return 'plantillas-whatsapp';
    }

    public function orientation(): Orientation
    {
        return Orientation::Landscape;
    }

    public function authorize(User $actor): bool
    {
        return Gate::forUser($actor)->allows('viewAny', MessageTemplate::class);
    }

    public function rules(): array
    {
        return MessageTemplateListQuery::rules();
    }

    public function describeFilters(ReportContext $context): array
    {
        $filters = $this->filters($context);
        $descriptions = [];

        $this->describeSearch($descriptions, $context);

        if ($filters['connection'] !== null) {
            $descriptions[] = 'Conexión: '.(Connection::query()->visibleTo($context->actor)->whereKey($filters['connection'])->value('name') ?? 'No disponible');
        }

        if ($filters['status'] !== null) {
            $descriptions[] = 'Estado: '.(MessageTemplateStatus::tryFrom($filters['status'])?->label() ?? $filters['status']);
        }

        if ($filters['category'] !== null) {
            $descriptions[] = 'Categoría: '.(MessageTemplateCategory::tryFrom($filters['category'])?->label() ?? $filters['category']);
        }

        if ($filters['language'] !== null) {
            $descriptions[] = 'Idioma: '.$filters['language'];
        }

        $this->describeRecordState($descriptions, $filters['record']);

        return $descriptions;
    }

    public function query(ReportContext $context): Builder
    {
        return $this->list->handle($context->actor, $this->filters($context))->with('channelConnection:id,name');
    }

    public function columns(): array
    {
        return [
            new Column('name', 'Plantilla', width: 22),
            new Column('language', 'Idioma', width: 9, unbreakable: 15),
            new Column('connection', 'Conexión', width: 16),
            new Column('category', 'Categoría', width: 13),
            new Column('status', 'Estado', width: 14),
            new Column('quality', 'Calidad', width: 10),
            new Column('preview', 'Contenido', width: 40),
            new Column('rejected_reason', 'Motivo de rechazo', width: 16),
            new Column('status_updated_at', 'Cambio de estado', ColumnType::DateTime, width: 15),
            new Column('last_synced_at', 'Actualizada desde Meta', ColumnType::DateTime, width: 15),
        ];
    }

    /**
     * @param  MessageTemplate  $record
     */
    public function map(Model $record, ReportContext $context): array
    {
        return [
            'name' => $record->name,
            'language' => $record->language,
            'connection' => $record->channelConnection->name,
            'category' => $record->categoryEnum()?->label() ?? $record->category,
            'status' => $record->isDeleted() ? 'Ya no existe en Meta' : $record->status->label(),
            'quality' => match (strtoupper((string) $record->quality_score)) {
                'GREEN', 'HIGH' => 'Alta',
                'YELLOW', 'MEDIUM' => 'Media',
                'RED', 'LOW' => 'Baja',
                default => 'Sin calificación',
            },
            'preview' => Str::limit($record->structure()->preview(), 500),
            'rejected_reason' => $record->rejected_reason,
            'status_updated_at' => $record->status_updated_at,
            'last_synced_at' => $record->last_synced_at,
        ];
    }

    public function metrics(ReportContext $context): array
    {
        $counts = $this->query($context)->reorder()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $total = (int) $counts->sum();
        $approved = (int) ($counts[MessageTemplateStatus::Approved->value] ?? 0);

        return [
            new Metric('Plantillas', $total, 'Con los filtros aplicados'),
            new Metric('Aprobadas por Meta', $approved, $this->shareOf($approved, $total)),
            new Metric('En revisión', (int) ($counts[MessageTemplateStatus::Pending->value] ?? 0)),
            new Metric('Rechazadas, pausadas o deshabilitadas', (int) collect([MessageTemplateStatus::Rejected, MessageTemplateStatus::Paused, MessageTemplateStatus::Disabled])
                ->sum(fn (MessageTemplateStatus $status): int => (int) ($counts[$status->value] ?? 0))),
        ];
    }

    public function charts(ReportContext $context): array
    {
        $counts = $this->query($context)->reorder()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $byStatus = [];

        foreach ($counts as $status => $total) {
            $byStatus[MessageTemplateStatus::tryFrom((string) $status)?->label() ?? (string) $status] = (int) $total;
        }

        $chart = $this->topCategories($byStatus);

        return [
            new Chart(ChartType::Donut, 'Estado de las plantillas', $chart['labels'], [new ChartSeries('Plantillas', $chart['values'])], 'Según lo informado por Meta'),
        ];
    }

    public function pdfOptions(): PdfOptions
    {
        return new PdfOptions(tableTitle: 'Detalle de plantillas');
    }

    /**
     * @return array{search: string|null, connection: int|null, status: string|null, category: string|null, language: string|null, record: RecordState}
     */
    private function filters(ReportContext $context): array
    {
        return $this->list->filters($context->actor, $context->filters);
    }
}
