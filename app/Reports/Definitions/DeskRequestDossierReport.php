<?php

namespace App\Reports\Definitions;

use App\Enums\TeamChat\TeamMessageKind;
use App\Models\TeamChat\TeamDeskNote;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Reports\Column;
use App\Reports\ColumnType;
use App\Reports\Metric;
use App\Reports\PdfOptions;
use App\Reports\Rendering\ValueFormatter;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportPanel;
use App\Reports\ReportSheet;
use App\Services\TeamChat\ControlDesk;
use App\Support\ProtectedAccounts;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Dossier of one «Mesa de control» thread, like the one of a customer
 * conversation: the request's data, its internal notes and its latest
 * messages (with the files each one carried). Authorized like the desk
 * section (TeamThreadPolicy::attendDesk); anyone else gets "not found".
 *
 * @extends ReportDefinition<TeamMessage>
 */
class DeskRequestDossierReport extends ReportDefinition
{
    public const MAX_MESSAGES = 1000;

    public const MAX_NOTES = 500;

    private const BODY_LENGTH = 2000;

    /** @var array<int, TeamThread|null> */
    private array $threads = [];

    public function __construct(private ControlDesk $desk) {}

    public function key(): string
    {
        return 'desk-request-dossier';
    }

    public function title(): string
    {
        return 'Expediente de solicitud';
    }

    public function subtitle(): ?string
    {
        return 'Mesa de control: datos de la solicitud, notas internas y mensajes';
    }

    public function fileName(): string
    {
        return 'expediente-mesa-de-control';
    }

    public function authorize(User $actor): bool
    {
        return $this->desk->attends($actor);
    }

    public function authorizeRecord(User $actor, array $filters): bool
    {
        $thread = $this->find($filters['thread'] ?? null);

        return $thread !== null && Gate::forUser($actor)->allows('attendDesk', $thread);
    }

    public function rules(): array
    {
        return ['thread' => ['required', 'integer', 'min:1']];
    }

    public function describeFilters(ReportContext $context): array
    {
        $thread = $this->thread($context);

        return ["Solicitud de {$this->requesterName($thread, $context)}"];
    }

    public function query(ReportContext $context): Builder
    {
        $thread = $this->thread($context);
        $firstId = $this->messages($thread)->orderByDesc('id')->skip(self::MAX_MESSAGES - 1)->value('id');

        return $this->messages($thread)
            ->when($firstId !== null, fn (Builder $query) => $query->where('id', '>=', $firstId))
            ->with(['author:id,name,is_active', 'attachments:id,team_message_id,original_name'])
            ->orderBy('id');
    }

    public function count(ReportContext $context): int
    {
        return min(self::MAX_MESSAGES, $this->messages($this->thread($context))->count());
    }

    public function columns(): array
    {
        return [
            new Column('created_at', 'Fecha', ColumnType::DateTime, width: 16),
            new Column('side', 'Quién escribió', width: 15),
            new Column('author', 'Autor', width: 20),
            new Column('body', 'Mensaje', width: 62),
            new Column('files', 'Archivos', width: 26),
        ];
    }

    /**
     * @param  TeamMessage  $record
     */
    public function map(Model $record, ReportContext $context): array
    {
        $thread = $this->thread($context);
        $fromRequester = $record->user_id !== null && $record->user_id === $thread->desk_requester_id;

        return [
            'created_at' => $record->created_at,
            'side' => $fromRequester ? 'Solicitante' : 'Mesa de control',
            'author' => $record->author === null ? 'Usuario eliminado' : app(ProtectedAccounts::class)->nameFor($record->author, $context->actor),
            'body' => $record->isDeleted() ? 'Se eliminó este mensaje.' : ($record->body === null || $record->body === '' ? null : Str::limit($record->body, self::BODY_LENGTH)),
            'files' => $record->attachments->pluck('original_name')->filter()->implode(', ') ?: null,
        ];
    }

    public function metrics(ReportContext $context): array
    {
        $thread = $this->thread($context);

        return [
            new Metric('Mensajes', $this->messages($thread)->count(), 'De la solicitante y de la mesa'),
            new Metric('Notas internas', $thread->deskNotes()->count(), 'Solo visibles para la mesa'),
        ];
    }

    public function panels(ReportContext $context): array
    {
        $thread = $this->thread($context);
        $names = app(ProtectedAccounts::class);
        $formatter = new ValueFormatter($context->timezone);
        $date = fn ($value): string => $value === null ? '—' : $formatter->formatAs($value, ColumnType::DateTime);
        $thread->loadMissing(['deskAssignee:id,name,is_active', 'deskCompletedBy:id,name,is_active', 'tags']);

        return [
            new ReportPanel('Solicitud', [
                'Solicitante' => $this->requesterName($thread, $context),
                'Estado' => $thread->desk_status?->label() ?? 'Sin solicitudes',
                'Atiende' => $thread->deskAssignee === null ? '—' : (string) $names->nameFor($thread->deskAssignee, $context->actor),
                'Solicitada' => $date($thread->desk_requested_at),
                'Aceptada' => $date($thread->desk_accepted_at),
                'Última vez completada' => $date($thread->desk_completed_at),
                'Completada por' => $thread->deskCompletedBy === null ? '—' : (string) $names->nameFor($thread->deskCompletedBy, $context->actor),
                'Etiquetas' => $thread->tags->pluck('name')->implode(', ') ?: '—',
            ]),
        ];
    }

    public function extraSheets(ReportContext $context): array
    {
        return [
            new ReportSheet('Notas internas', [
                new Column('created_at', 'Fecha', ColumnType::DateTime, width: 16),
                new Column('author', 'Autor', width: 20),
                new Column('body', 'Nota', width: 62),
                new Column('edited_at', 'Editada', ColumnType::DateTime, width: 16),
            ], $this->notes($this->thread($context), $context), inPdf: true, beforeTable: true,
                description: 'Visibles solo para la mesa de control; la solicitante nunca las ve.'),
        ];
    }

    public function pdfOptions(): PdfOptions
    {
        return new PdfOptions(includeCharts: false, tableTitle: 'Mensajes');
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function notes(TeamThread $thread, ReportContext $context): Generator
    {
        $notes = $thread->deskNotes()->with('author:id,name,is_active')->orderBy('id')->limit(self::MAX_NOTES)->get();

        foreach ($notes as $note) {
            /** @var TeamDeskNote $note */
            yield [
                'created_at' => $note->created_at,
                'author' => $note->author === null ? 'Usuario eliminado' : app(ProtectedAccounts::class)->nameFor($note->author, $context->actor),
                'body' => Str::limit($note->body, self::BODY_LENGTH),
                'edited_at' => $note->edited_at,
            ];
        }
    }

    /**
     * @return Builder<TeamMessage>
     */
    private function messages(TeamThread $thread): Builder
    {
        return TeamMessage::query()->where('team_thread_id', $thread->id)->where('kind', TeamMessageKind::Message->value);
    }

    private function requesterName(TeamThread $thread, ReportContext $context): string
    {
        $thread->loadMissing('deskRequester:id,name,is_active');

        return $thread->deskRequester === null ? 'Usuario eliminado' : (string) app(ProtectedAccounts::class)->nameFor($thread->deskRequester, $context->actor);
    }

    private function thread(ReportContext $context): TeamThread
    {
        return $this->find($context->filter('thread')) ?? abort(404);
    }

    private function find(mixed $id): ?TeamThread
    {
        if (! is_numeric($id)) {
            return null;
        }

        $id = (int) $id;

        if (! array_key_exists($id, $this->threads)) {
            $thread = TeamThread::query()->find($id);
            $this->threads[$id] = $thread !== null && $thread->isDesk() ? $thread : null;
        }

        return $this->threads[$id];
    }
}
