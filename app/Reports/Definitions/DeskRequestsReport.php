<?php

namespace App\Reports\Definitions;

use App\Enums\TeamChat\TeamDeskStatus;
use App\Enums\TeamChat\TeamMessageKind;
use App\Enums\TeamChat\TeamThreadType;
use App\Models\Tag;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Reports\Column;
use App\Reports\ColumnTotal;
use App\Reports\ColumnType;
use App\Reports\Metric;
use App\Reports\Orientation;
use App\Reports\PdfOptions;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Services\TeamChat\ControlDesk;
use App\Support\ProtectedAccounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Requests of the «Mesa de control»: who asked, their state, who attends
 * or completed them, their times, tags and how many messages and internal
 * notes they have. Only for people who attend the desk (the same reach as
 * the desk section, which every auditor reads in full).
 *
 * @extends ReportDefinition<TeamThread>
 */
class DeskRequestsReport extends ReportDefinition
{
    public const TABS = ['all', 'pending', 'mine', 'completed', 'open'];

    public function __construct(private ControlDesk $desk) {}

    public function key(): string
    {
        return 'desk-requests';
    }

    public function title(): string
    {
        return 'Solicitudes de la mesa de control';
    }

    public function subtitle(): ?string
    {
        return 'Solicitantes, estado, quién atiende, tiempos, etiquetas y notas internas';
    }

    public function orientation(): Orientation
    {
        return Orientation::Landscape;
    }

    public function fileName(): string
    {
        return 'solicitudes-mesa-de-control';
    }

    public function authorize(User $actor): bool
    {
        return $this->desk->attends($actor);
    }

    public function rules(): array
    {
        return [
            'tab' => ['nullable', Rule::in(self::TABS)],
            'tag' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function describeFilters(ReportContext $context): array
    {
        $descriptions = [];
        $tab = (string) $context->filter('tab', 'all');

        if ($tab !== 'all') {
            $descriptions[] = 'Solicitudes: '.match ($tab) {
                'pending' => 'Pendientes',
                'mine' => 'Las que atiendes',
                'open' => 'En atención',
                default => 'Completadas',
            };
        }

        if (is_numeric($context->filter('tag'))) {
            $descriptions[] = 'Etiqueta: '.(Tag::query()->whereKey((int) $context->filter('tag'))->value('name') ?? '—');
        }

        return $descriptions;
    }

    public function query(ReportContext $context): Builder
    {
        return $this->base($context)
            ->with(['deskRequester:id,name,is_active', 'deskAssignee:id,name,is_active', 'deskCompletedBy:id,name,is_active', 'tags' => fn ($query) => $query->orderBy('tags.name')])
            ->withCount([
                'messages as messages_count' => fn (Builder $query) => $query->where('kind', TeamMessageKind::Message->value)->whereNull('deleted_at'),
                'deskNotes as notes_count',
            ])
            ->orderByDesc('desk_requested_at')
            ->orderByDesc('id');
    }

    public function count(ReportContext $context): int
    {
        return $this->base($context)->count();
    }

    public function columns(): array
    {
        return [
            new Column('requester', 'Solicitante', width: 24),
            new Column('status', 'Estado', width: 13),
            new Column('assignee', 'Atiende', width: 20),
            new Column('requested_at', 'Solicitada', ColumnType::DateTime, width: 17),
            new Column('accepted_at', 'Aceptada', ColumnType::DateTime, width: 17),
            new Column('completed_at', 'Completada', ColumnType::DateTime, width: 17),
            new Column('completed_by', 'Completada por', width: 20),
            new Column('tags', 'Etiquetas', width: 24),
            new Column('messages', 'Mensajes', ColumnType::Number, width: 10, total: ColumnTotal::Sum),
            new Column('notes', 'Notas', ColumnType::Number, width: 8, total: ColumnTotal::Sum),
        ];
    }

    /**
     * @param  TeamThread  $record
     */
    public function map(Model $record, ReportContext $context): array
    {
        $names = app(ProtectedAccounts::class);

        return [
            'requester' => $names->nameFor($record->deskRequester, $context->actor) ?? 'Usuario eliminado',
            'status' => $record->desk_status?->label() ?? 'Sin solicitudes',
            'assignee' => $record->desk_status === TeamDeskStatus::Open ? $names->nameFor($record->deskAssignee, $context->actor) : null,
            'requested_at' => $record->desk_requested_at,
            'accepted_at' => $record->desk_status === TeamDeskStatus::Open ? $record->desk_accepted_at : null,
            'completed_at' => $record->desk_completed_at,
            'completed_by' => $record->desk_completed_by_user_id === null ? null : $names->nameFor($record->deskCompletedBy, $context->actor),
            'tags' => $record->tags->pluck('name')->implode(', ') ?: null,
            'messages' => (int) $record->getAttribute('messages_count'),
            'notes' => (int) $record->getAttribute('notes_count'),
        ];
    }

    public function metrics(ReportContext $context): array
    {
        $count = fn (TeamDeskStatus $status): int => $this->base($context)->where('desk_status', $status)->count();

        return [
            new Metric('Solicitudes', $this->count($context), 'Con los filtros aplicados'),
            new Metric('En espera', $count(TeamDeskStatus::Pending), 'Sin aceptar'),
            new Metric('En atención', $count(TeamDeskStatus::Open), 'Aceptadas y abiertas'),
            new Metric('Completadas', $count(TeamDeskStatus::Closed), 'Última solicitud completada'),
        ];
    }

    public function pdfOptions(): PdfOptions
    {
        return new PdfOptions(includeCharts: false, tableTitle: 'Solicitudes');
    }

    /**
     * @return Builder<TeamThread>
     */
    private function base(ReportContext $context): Builder
    {
        $tab = (string) $context->filter('tab', 'all');
        $tag = $context->filter('tag');

        return TeamThread::query()
            ->where('type', TeamThreadType::Desk)
            ->whereNotNull('desk_status')
            ->when($tab === 'pending', fn (Builder $query) => $query->where('desk_status', TeamDeskStatus::Pending))
            ->when($tab === 'open', fn (Builder $query) => $query->where('desk_status', TeamDeskStatus::Open))
            ->when($tab === 'mine', fn (Builder $query) => $query->where('desk_status', TeamDeskStatus::Open)->where('desk_assigned_user_id', $context->actor->id))
            ->when($tab === 'completed', fn (Builder $query) => $query->where('desk_status', TeamDeskStatus::Closed))
            ->when(is_numeric($tag), fn (Builder $query) => $query->whereHas('tags', fn (Builder $tags) => $tags->whereKey((int) $tag)));
    }
}
