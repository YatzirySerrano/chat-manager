<?php

namespace App\Reports\Definitions;

use App\Actions\Connections\ConnectionListQuery;
use App\Enums\ConnectionChannel;
use App\Enums\ConnectionHealth;
use App\Models\Connection;
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
 * Connections (WhatsApp numbers) mirroring the connections screen, with
 * non-sensitive metadata only. Credentials (access token, app secret,
 * webhook verify token) are needed in memory to derive the health, but
 * map() never exports them; the phone number id is an identifier, not a
 * secret.
 *
 * @phpstan-import-type ConnectionFilters from ConnectionListQuery
 *
 * @extends ReportDefinition<Connection>
 */
class ConnectionsReport extends ReportDefinition
{
    use DescribesReports;

    public function __construct(private ConnectionListQuery $list) {}

    public function key(): string
    {
        return 'connections';
    }

    public function title(): string
    {
        return 'Conexiones';
    }

    public function subtitle(): ?string
    {
        return 'Números de WhatsApp, su estado y su enrutamiento (sin credenciales)';
    }

    public function fileName(): string
    {
        return 'conexiones';
    }

    public function orientation(): Orientation
    {
        return Orientation::Landscape;
    }

    public function authorize(User $actor): bool
    {
        return Gate::forUser($actor)->allows('viewAny', Connection::class);
    }

    public function rules(): array
    {
        return ConnectionListQuery::rules();
    }

    public function describeFilters(ReportContext $context): array
    {
        $filters = $this->filters($context);
        $descriptions = [];

        $this->describeSearch($descriptions, $context);

        if ($filters['channel'] !== null) {
            $descriptions[] = 'Canal: '.(ConnectionChannel::tryFrom($filters['channel'])?->label() ?? $filters['channel']);
        }

        if ($filters['health'] !== null) {
            $descriptions[] = 'Salud: '.(ConnectionHealth::tryFrom($filters['health'])?->label() ?? $filters['health']);
        }

        if ($filters['department'] !== null) {
            $descriptions[] = 'Departamento: '.(Department::query()->whereKey($filters['department'])->value('name') ?? 'No disponible');
        }

        if ($filters['status'] !== null) {
            $descriptions[] = 'Operación: '.($filters['status'] === 'active' ? 'Activas' : 'Pausadas');
        }

        $this->describeRecordState($descriptions, $filters['record']);

        return $descriptions;
    }

    public function query(ReportContext $context): Builder
    {
        $filters = $this->filters($context);
        $query = $this->list->handle($context->actor, $filters)->with('defaultDepartment:id,name');

        if ($filters['health'] !== null) {
            $query->whereKey($this->list->matching($context->actor, $filters)->modelKeys());
        }

        return $query;
    }

    public function count(ReportContext $context): int
    {
        return $this->connections($context)->count();
    }

    public function columns(): array
    {
        return [
            new Column('name', 'Conexión', width: 18),
            new Column('channel', 'Canal', width: 11, unbreakable: 21),
            new Column('display_phone', 'Número', width: 17, unbreakable: 29),
            new Column('phone_number_id', 'ID del número', width: 18, unbreakable: 31),
            new Column('status', 'Estado', width: 12),
            new Column('health', 'Salud', width: 18),
            new Column('default_department', 'Predeterminado', width: 17),
            new Column('departments', 'Departamentos', width: 24),
            new Column('conversations', 'Conversaciones', ColumnType::Number, width: 15, total: ColumnTotal::Sum),
            new Column('last_inbound_at', 'Último entrante', ColumnType::DateTime, width: 15),
            new Column('last_outbound_at', 'Último saliente', ColumnType::DateTime, width: 15),
            new Column('created_at', 'Fecha de alta', ColumnType::DateTime, width: 15),
        ];
    }

    /**
     * Only the listed, non-sensitive attributes are exported.
     *
     * @param  Connection  $record
     */
    public function map(Model $record, ReportContext $context): array
    {
        return [
            'name' => $record->name,
            'channel' => $record->channel->label(),
            'display_phone' => $record->display_phone_number,
            'phone_number_id' => $record->phone_number_id,
            'status' => match (true) {
                $record->isDeleted() => 'Eliminada',
                $record->is_active => 'Activa',
                default => 'Pausada',
            },
            'health' => $record->health()->label(),
            'default_department' => $record->defaultDepartment?->name,
            'departments' => $record->departments->pluck('name')->implode(', ') ?: null,
            'conversations' => (int) $record->getAttribute('conversations_count'),
            'last_inbound_at' => $record->last_inbound_at,
            'last_outbound_at' => $record->last_outbound_at,
            'created_at' => $record->created_at,
        ];
    }

    public function metrics(ReportContext $context): array
    {
        $connections = $this->connections($context);
        $total = $connections->count();
        $active = $connections->filter(fn (Connection $connection): bool => $connection->isOperational())->count();
        $verified = $connections->filter(fn (Connection $connection): bool => in_array(
            $connection->configurationHealth(),
            [ConnectionHealth::CredentialsVerified, ConnectionHealth::Receiving, ConnectionHealth::Operational],
            true,
        ))->count();
        $attention = $connections->filter(fn (Connection $connection): bool => in_array(
            $connection->configurationHealth(),
            [ConnectionHealth::NotConfigured, ConnectionHealth::CredentialsInvalid, ConnectionHealth::Error],
            true,
        ))->count();

        return [
            new Metric('Conexiones', $total, 'Con los filtros aplicados'),
            new Metric('Operando', $active, 'Activas y no eliminadas'),
            new Metric('Credenciales verificadas', $verified, $this->shareOf($verified, $total)),
            new Metric('Requieren atención', $attention, 'Sin configurar, credenciales inválidas o con error'),
        ];
    }

    public function charts(ReportContext $context): array
    {
        $connections = $this->connections($context);
        $byHealth = [];

        foreach ($connections as $connection) {
            $label = $connection->health()->label();
            $byHealth[$label] = ($byHealth[$label] ?? 0) + 1;
        }

        $health = $this->topCategories($byHealth);
        $byConversations = $connections->sortByDesc(fn (Connection $connection): int => (int) $connection->getAttribute('conversations_count'))->take(10)->values();

        return [
            new Chart(
                ChartType::Bar,
                'Conversaciones por conexión',
                array_values($byConversations->map(fn (Connection $connection): string => $connection->name)->all()),
                [new ChartSeries('Conversaciones', array_values($byConversations->map(fn (Connection $connection): int => (int) $connection->getAttribute('conversations_count'))->all()))],
                'Conversaciones recibidas por cada número (top 10)',
            ),
            new Chart(
                ChartType::Donut,
                'Salud de las conexiones',
                $health['labels'],
                [new ChartSeries('Conexiones', $health['values'])],
                'Estado derivado de la configuración y el tráfico registrado',
            ),
        ];
    }

    public function pdfOptions(): PdfOptions
    {
        return new PdfOptions(tableTitle: 'Detalle de conexiones');
    }

    /**
     * @return Collection<int, Connection>
     */
    private function connections(ReportContext $context): Collection
    {
        return $this->list->matching($context->actor, $this->filters($context));
    }

    /**
     * @return ConnectionFilters
     */
    private function filters(ReportContext $context): array
    {
        return $this->list->filters($context->actor, $context->filters);
    }
}
