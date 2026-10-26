<?php

namespace App\Reports\Definitions;

use App\Actions\Conversations\ConversationInboxQuery;
use App\Enums\ConnectionChannel;
use App\Enums\ConversationStatus;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
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
use App\Services\Conversations\CustomerServiceWindow;
use App\Support\PhoneNumber;
use App\Support\ProtectedAccounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Administrative metadata of the conversations inbox (never message
 * bodies), with exactly the screen's views, filters and reach: an agent
 * only exports their own conversations and the queue they may accept from.
 *
 * @phpstan-import-type InboxFilters from ConversationInboxQuery
 *
 * @extends ReportDefinition<Conversation>
 */
class ConversationsReport extends ReportDefinition
{
    use DescribesReports;

    private const TOP_DEPARTMENTS = 10;

    /**
     * @var array<string, string>
     */
    private const VIEW_LABELS = [
        ConversationInboxQuery::VIEW_GENERAL => 'Bandeja general (pendientes por aceptar y en atención)',
        ConversationInboxQuery::VIEW_MINE => 'Asignadas a ti',
        ConversationInboxQuery::VIEW_QUEUE => 'Pendientes por aceptar',
    ];

    /**
     * Name of the "all" view after the actor's reach, as the inbox shows it.
     *
     * @var array<string, string>
     */
    private const ALL_VIEW_LABELS = [
        'global' => 'Todas las conversaciones',
        'departments' => 'Conversaciones de mis departamentos',
    ];

    public function __construct(
        private ConversationInboxQuery $inbox,
        private CustomerServiceWindow $window,
    ) {}

    public function key(): string
    {
        return 'conversations';
    }

    public function title(): string
    {
        return 'Conversaciones';
    }

    public function subtitle(): ?string
    {
        return 'Datos administrativos de la atención (sin contenido de mensajes)';
    }

    public function fileName(): string
    {
        return 'conversaciones';
    }

    public function orientation(): Orientation
    {
        return Orientation::Landscape;
    }

    public function authorize(User $actor): bool
    {
        return Gate::forUser($actor)->allows('viewAny', Conversation::class);
    }

    public function rules(): array
    {
        return ConversationInboxQuery::rules();
    }

    public function describeFilters(ReportContext $context): array
    {
        $filters = $this->filters($context);
        $actor = $context->actor;
        // A personal inbox reaches only its own conversations: "all" of them
        // are the ones assigned to the actor.
        $descriptions = ['Vista: '.match (true) {
            $filters['view'] !== ConversationInboxQuery::VIEW_ALL => self::VIEW_LABELS[$filters['view']],
            $filters['personal'] => self::VIEW_LABELS[ConversationInboxQuery::VIEW_MINE],
            default => self::ALL_VIEW_LABELS[$actor->dataScope()->value ?? ''] ?? self::VIEW_LABELS[ConversationInboxQuery::VIEW_MINE],
        }];

        $descriptions[] = 'Estado: '.match ($filters['status']) {
            'active' => 'Sin cerrar',
            'all' => 'Todos',
            default => ConversationStatus::tryFrom($filters['status'])?->label() ?? $filters['status'],
        };

        if ($filters['department'] !== null) {
            $descriptions[] = 'Departamento: '.($filters['department'] === ConversationInboxQuery::NONE
                ? 'Sin departamento'
                : (Department::query()->visibleTo($actor)->whereKey((int) $filters['department'])->value('name') ?? 'No disponible'));
        }

        if ($filters['agent'] !== null) {
            $descriptions[] = 'Agente: '.($filters['agent'] === ConversationInboxQuery::NONE
                ? 'Sin agente'
                : (User::query()->visibleTo($actor)->withoutProtectedFor($actor)->whereKey((int) $filters['agent'])->value('name') ?? 'No disponible'));
        }

        if ($filters['connection'] !== null) {
            $descriptions[] = 'Conexión: '.(Connection::query()->visibleTo($actor)->whereKey($filters['connection'])->value('name') ?? 'No disponible');
        }

        if (($filters['channel'] ?? null) !== null) {
            $descriptions[] = 'Canal: '.(ConnectionChannel::tryFrom((string) $filters['channel'])?->label() ?? $filters['channel']);
        }

        $tagIds = $filters['tags'] ?? [];

        if ($tagIds !== []) {
            $names = Tag::query()->whereKey($tagIds)->orderBy('name')->pluck('name')->all();
            $matchesAll = ($filters['tag_match'] ?? ConversationInboxQuery::TAG_MATCH_ANY) === ConversationInboxQuery::TAG_MATCH_ALL;
            $descriptions[] = (count($names) > 1
                ? ($matchesAll ? 'Con todas estas etiquetas a la vez: ' : 'Con al menos una de estas etiquetas: ')
                : 'Etiqueta: ').implode(', ', $names === [] ? ['No disponible'] : $names);
        }

        if ($filters['search'] !== '') {
            $descriptions[] = (($filters['search_in'] ?? ConversationInboxQuery::SEARCH_CONTACT) === ConversationInboxQuery::SEARCH_MESSAGES
                ? 'Búsqueda en el contenido de los mensajes: «'
                : 'Búsqueda: «').$filters['search'].'»';
        }

        return $descriptions;
    }

    public function query(ReportContext $context): Builder
    {
        return $this->inbox->handle($context->actor, $this->filters($context))
            ->with([
                'contact:id,name,phone_e164',
                'department:id,name',
                'assignee:id,name',
                'channelConnection:id,name,channel',
            ]);
    }

    public function count(ReportContext $context): int
    {
        return $this->aggregate($context)->count();
    }

    public function columns(): array
    {
        return [
            new Column('id', 'Folio', ColumnType::Number, width: 8),
            new Column('contact', 'Contacto', width: 20),
            new Column('phone', 'Teléfono', width: 16, unbreakable: 29),
            new Column('status', 'Estado', width: 13),
            new Column('department', 'Departamento', width: 16),
            new Column('agent', 'Agente', width: 17),
            new Column('connection', 'Conexión', width: 15),
            new Column('created_at', 'Creada', ColumnType::DateTime, width: 15),
            new Column('last_message_at', 'Último mensaje', ColumnType::DateTime, width: 15),
            new Column('last_inbound_at', 'Último del cliente', ColumnType::DateTime, width: 15),
            new Column('closed_at', 'Cerrada el', ColumnType::DateTime, width: 15),
            new Column('window', 'Ventana de 24 h para responder', width: 14),
        ];
    }

    /**
     * @param  Conversation  $record
     */
    public function map(Model $record, ReportContext $context): array
    {
        return [
            'id' => $record->id,
            'contact' => $record->contact->name,
            'phone' => PhoneNumber::format($record->contact->phone_e164),
            'status' => $record->status->label(),
            'department' => $record->department->name ?? 'Sin departamento',
            'agent' => app(ProtectedAccounts::class)->nameFor($record->assignee, $context->actor) ?? 'Sin asignar',
            'connection' => $record->channelConnection->name,
            'created_at' => $record->created_at,
            'last_message_at' => $record->last_message_at,
            'last_inbound_at' => $record->last_inbound_at,
            'closed_at' => $record->closed_at,
            'window' => $this->window->for($record, $context->generatedAt)->status->label(),
        ];
    }

    public function metrics(ReportContext $context): array
    {
        $byStatus = $this->statusCounts($context);
        $total = array_sum($byStatus);

        return [
            new Metric('Conversaciones', $total, 'Con los filtros aplicados'),
            ...array_map(
                fn (ConversationStatus $status): Metric => new Metric($status->label(), $byStatus[$status->value], $this->shareOf($byStatus[$status->value], $total)),
                ConversationStatus::operational(),
            ),
        ];
    }

    public function charts(ReportContext $context): array
    {
        $byStatus = $this->statusCounts($context);
        $perDepartment = $this->aggregate($context)
            ->toBase()
            ->selectRaw('conversations.department_id AS department_id, COUNT(*) AS total')
            ->groupBy('conversations.department_id')
            ->orderByDesc('total')
            ->limit(self::TOP_DEPARTMENTS)
            ->pluck('total', 'department_id');
        $names = Department::query()->whereKey($perDepartment->keys()->filter()->all())->pluck('name', 'id');
        $departments = $perDepartment->mapWithKeys(fn (int|string $total, int|string $id): array => [
            (string) ($names[$id] ?? 'Sin departamento') => (int) $total,
        ]);

        return [
            new Chart(
                ChartType::Donut,
                'Conversaciones por estado',
                array_map(fn (ConversationStatus $status): string => $status->label(), ConversationStatus::operational()),
                [new ChartSeries('Conversaciones', array_values($byStatus))],
                'Distribución de las conversaciones exportadas',
            ),
            new Chart(
                ChartType::Bar,
                'Conversaciones por departamento',
                array_values(array_map('strval', $departments->keys()->all())),
                [new ChartSeries('Conversaciones', array_values(array_map('intval', $departments->values()->all())))],
                'Departamentos con más conversaciones (top '.self::TOP_DEPARTMENTS.')',
            ),
        ];
    }

    public function pdfOptions(): PdfOptions
    {
        return new PdfOptions(tableTitle: 'Detalle de conversaciones');
    }

    /**
     * Legacy on_hold rows (not migrated yet) are counted as open.
     *
     * @return array<string, int> Status value => count, in the operational statuses' order.
     */
    private function statusCounts(ReportContext $context): array
    {
        $counts = $this->aggregate($context)
            ->toBase()
            ->selectRaw('conversations.status AS status, COUNT(*) AS total')
            ->groupBy('conversations.status')
            ->pluck('total', 'status');

        $result = [];

        $legacyOnHold = (int) ($counts[ConversationStatus::OnHold->value] ?? 0);

        foreach (ConversationStatus::operational() as $status) {
            $result[$status->value] = (int) ($counts[$status->value] ?? 0)
                + ($status === ConversationStatus::Open ? $legacyOnHold : 0);
        }

        return $result;
    }

    /**
     * The exported conversations without ordering or relations.
     *
     * @return Builder<Conversation>
     */
    private function aggregate(ReportContext $context): Builder
    {
        return $this->inbox->handle($context->actor, $this->filters($context))->reorder();
    }

    /**
     * @return InboxFilters
     */
    private function filters(ReportContext $context): array
    {
        return $this->inbox->filters($context->actor, $context->filters);
    }
}
