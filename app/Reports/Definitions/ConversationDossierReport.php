<?php

namespace App\Reports\Definitions;

use App\Actions\Messaging\ReconcileStuckDeliveries;
use App\Enums\AttributionReliability;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Models\ContactAttribution;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\ConversationNote;
use App\Models\Message;
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
use App\Services\Conversations\CustomerServiceWindow;
use App\Services\ResolveContactOrigin;
use App\Support\PhoneNumber;
use App\Support\ProtectedAccounts;
use Carbon\CarbonInterface;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Dossier of one conversation: header data, contact and origin, the
 * operational timeline (only with the history permission, as on the
 * detail page), the internal notes shown on that page and the latest
 * messages up to a fixed maximum stated in the document.
 *
 * Authorized like the detail page (the conversation "view" policy) on top
 * of reports.export; a conversation outside the actor's reach is answered
 * as not found.
 *
 * @extends ReportDefinition<Message>
 */
class ConversationDossierReport extends ReportDefinition
{
    /**
     * Messages included at most: the latest ones, in chronological order.
     */
    public const MAX_MESSAGES = 500;

    /**
     * Timeline events and notes included at most.
     */
    public const MAX_ENTRIES = 500;

    /**
     * Characters of a message body kept in the document.
     */
    private const BODY_LENGTH = 2000;

    /**
     * Conversations resolved per request, so the many hooks of one export
     * read the record once.
     *
     * @var array<int, Conversation|null>
     */
    private array $conversations = [];

    public function __construct(
        private CustomerServiceWindow $window,
        private ResolveContactOrigin $resolveOrigin,
    ) {}

    public function key(): string
    {
        return 'conversation-dossier';
    }

    public function title(): string
    {
        return 'Expediente de conversación';
    }

    public function subtitle(): ?string
    {
        return 'Datos, contacto, origen, línea de tiempo, notas internas y mensajes';
    }

    public function fileName(): string
    {
        return 'expediente-conversacion';
    }

    public function authorize(User $actor): bool
    {
        return Gate::forUser($actor)->allows('viewAny', Conversation::class);
    }

    public function authorizeRecord(User $actor, array $filters): bool
    {
        $conversation = $this->find($filters['conversation'] ?? null);

        return $conversation !== null && Gate::forUser($actor)->allows('view', $conversation);
    }

    public function rules(): array
    {
        return [
            'conversation' => ['required', 'integer', 'min:1'],
        ];
    }

    public function describeFilters(ReportContext $context): array
    {
        $conversation = $this->conversation($context);

        return ["Conversación #{$conversation->id} · {$conversation->contact->name}"];
    }

    public function query(ReportContext $context): Builder
    {
        $conversation = $this->conversation($context);
        $firstId = $conversation->messages()
            ->orderByDesc('id')
            ->skip(self::MAX_MESSAGES - 1)
            ->value('id');

        return Message::query()
            ->where('conversation_id', $conversation->id)
            ->when($firstId !== null, fn (Builder $query) => $query->where('id', '>=', $firstId))
            ->with('sender:id,name')
            ->orderBy('id');
    }

    public function count(ReportContext $context): int
    {
        return min(self::MAX_MESSAGES, $this->conversation($context)->messages()->count());
    }

    public function columns(): array
    {
        return [
            new Column('sent_at', 'Fecha', ColumnType::DateTime, width: 15),
            new Column('direction', 'Quién escribió', width: 10),
            new Column('sender', 'Autor', width: 18),
            new Column('type', 'Tipo', width: 11),
            new Column('body', 'Mensaje', width: 62),
            new Column('status', 'Estado', width: 11),
        ];
    }

    /**
     * @param  Message  $record
     */
    public function map(Model $record, ReportContext $context): array
    {
        $inbound = $record->direction === MessageDirection::Inbound;

        return [
            'sent_at' => $record->sent_at ?? $record->created_at,
            'direction' => $inbound ? 'Cliente' : 'Equipo',
            'sender' => $inbound ? $this->conversation($context)->contact->name : ($this->nameFor($record->sender, $context) ?? 'Sistema'),
            'type' => $this->typeLabel($record->type),
            'body' => $record->body === null || $record->body === '' ? null : Str::limit($record->body, self::BODY_LENGTH),
            'status' => $this->statusLabel($record),
        ];
    }

    public function panels(ReportContext $context): array
    {
        $conversation = $this->conversation($context);
        $contact = $conversation->contact;
        $formatter = new ValueFormatter($context->timezone);
        $formatDate = fn (?CarbonInterface $date): string => $formatter->formatAs($date, ColumnType::DateTime);
        $origin = $this->resolveOrigin->best($conversation->attributions()->orderBy('captured_at')->get());
        $total = $conversation->messages()->count();

        return [
            new ReportPanel('Conversación', [
                'Folio' => '#'.$conversation->id,
                'Estado' => $conversation->status->label(),
                'Departamento' => $conversation->department->name ?? 'Sin departamento',
                'Agente' => $this->nameFor($conversation->assignee, $context) ?? 'Sin asignar',
                'Conexión' => $conversation->channelConnection->name,
                'Creada' => $formatDate($conversation->created_at),
                'Primera respuesta' => $formatDate($conversation->first_response_at),
                'Último mensaje' => $formatDate($conversation->last_message_at),
                'Cerrada' => $formatDate($conversation->closed_at),
                'Cerrada por' => $this->nameFor($conversation->closedBy, $context),
                'Reaperturas' => (string) $conversation->reopen_count,
                'Ventana de 24 h para responder' => $this->window->for($conversation, $context->generatedAt)->status->label(),
            ]),
            new ReportPanel('Contacto y origen', [
                'Nombre' => $contact->name,
                'Nombre en WhatsApp' => $contact->profile_name,
                'Teléfono' => PhoneNumber::format($contact->phone_e164),
                'Correo electrónico' => $contact->email,
                'Último mensaje del cliente' => $formatDate($conversation->last_inbound_at),
                'Origen' => $origin === null ? 'Origen no identificado' : $origin->origin_type->label(),
                'Confiabilidad' => $origin === null ? null : $this->reliabilityLabel($origin),
                'Campaña o anuncio' => $origin === null ? null : ($origin->campaign_name ?? $origin->headline ?? $origin->page_name),
            ], $total > self::MAX_MESSAGES
                ? 'La conversación tiene '.number_format($total).' mensajes: el expediente incluye los últimos '.number_format(self::MAX_MESSAGES).'.'
                : 'El expediente incluye los '.number_format($total).' mensajes de la conversación (máximo '.number_format(self::MAX_MESSAGES).').'),
        ];
    }

    public function metrics(ReportContext $context): array
    {
        $conversation = $this->conversation($context);
        $total = $conversation->messages()->count();
        $inbound = $conversation->messages()->where('direction', MessageDirection::Inbound)->count();

        return [
            new Metric('Mensajes', $total, $total > self::MAX_MESSAGES ? 'Se incluyen los últimos '.number_format(self::MAX_MESSAGES) : 'Todos incluidos'),
            new Metric('Del cliente', $inbound),
            new Metric('Del equipo', $total - $inbound),
            new Metric('Notas internas', $conversation->notes()->count()),
        ];
    }

    public function extraSheets(ReportContext $context): array
    {
        $conversation = $this->conversation($context);
        $sheets = [];

        if (Gate::forUser($context->actor)->allows('viewHistory', $conversation)) {
            $sheets[] = new ReportSheet('Línea de tiempo', [
                new Column('created_at', 'Fecha', ColumnType::DateTime, width: 15),
                new Column('event', 'Evento', width: 22),
                new Column('actor', 'Realizado por', width: 18),
                new Column('detail', 'Detalle', width: 45),
            ], $this->timeline($conversation, $context), inPdf: true, beforeTable: true,
                description: 'Asignaciones, transferencias, esperas y cierres (máximo '.number_format(self::MAX_ENTRIES).' eventos).');
        }

        $sheets[] = new ReportSheet('Notas internas', [
            new Column('created_at', 'Fecha', ColumnType::DateTime, width: 15),
            new Column('author', 'Autor', width: 18),
            new Column('body', 'Nota', width: 62),
            new Column('edited_at', 'Editada', ColumnType::DateTime, width: 15),
        ], $this->notes($conversation, $context), inPdf: true, beforeTable: true,
            description: 'Visibles solo para el equipo; nunca se envían al cliente (las primeras '.number_format(self::MAX_ENTRIES).' notas; el historial completo se consulta en la conversación).');

        return $sheets;
    }

    public function pdfOptions(): PdfOptions
    {
        return new PdfOptions(includeCharts: false, tableTitle: 'Mensajes');
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function timeline(Conversation $conversation, ReportContext $context): Generator
    {
        $events = $conversation->events()
            ->with(['actor:id,name', 'fromUser:id,name', 'toUser:id,name', 'fromDepartment:id,name', 'toDepartment:id,name'])
            ->orderByDesc('id')
            ->limit(self::MAX_ENTRIES)
            ->get()
            ->reverse();

        foreach ($events as $event) {
            /** @var ConversationEvent $event */
            $detail = array_filter([
                $event->fromDepartment !== null || $event->toDepartment !== null
                    ? 'Departamento: '.($event->fromDepartment->name ?? '—').' → '.($event->toDepartment->name ?? '—')
                    : null,
                $event->fromUser !== null || $event->toUser !== null
                    ? 'Agente: '.($this->nameFor($event->fromUser, $context) ?? '—').' → '.($this->nameFor($event->toUser, $context) ?? '—')
                    : null,
                $event->note,
            ]);

            yield [
                'created_at' => $event->created_at,
                'event' => $event->type->label(),
                'actor' => $this->nameFor($event->actor, $context) ?? 'Sistema',
                'detail' => $detail === [] ? null : implode(' · ', $detail),
            ];
        }
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function notes(Conversation $conversation, ReportContext $context): Generator
    {
        $notes = $conversation->notes()->with('author:id,name')->orderBy('id')->limit(self::MAX_ENTRIES)->get();

        foreach ($notes as $note) {
            /** @var ConversationNote $note */
            yield [
                'created_at' => $note->created_at,
                'author' => $this->nameFor($note->author, $context) ?? 'Usuario eliminado',
                'body' => Str::limit($note->body, self::BODY_LENGTH),
                'edited_at' => $note->edited_at,
            ];
        }
    }

    /**
     * The person's name as the actor may see it: protected accounts (root
     * role) are presented as the system administration to everyone else.
     */
    private function nameFor(?User $user, ReportContext $context): ?string
    {
        return app(ProtectedAccounts::class)->nameFor($user, $context->actor);
    }

    private function conversation(ReportContext $context): Conversation
    {
        return $this->find($context->filter('conversation')) ?? abort(404);
    }

    private function find(mixed $id): ?Conversation
    {
        if (! is_numeric($id)) {
            return null;
        }

        $id = (int) $id;

        if (! array_key_exists($id, $this->conversations)) {
            $this->conversations[$id] = Conversation::query()
                ->with(['contact', 'channelConnection:id,name,channel', 'department:id,name', 'assignee:id,name', 'closedBy:id,name'])
                ->find($id);
        }

        return $this->conversations[$id];
    }

    private function reliabilityLabel(ContactAttribution $origin): string
    {
        return $origin->reliability === AttributionReliability::ProviderConfirmed && $origin->provider === 'meta'
            ? 'Confirmado por Meta'
            : $origin->reliability->label();
    }

    private function typeLabel(MessageType $type): string
    {
        return match ($type) {
            MessageType::Text => 'Texto',
            MessageType::Image => 'Imagen',
            MessageType::Audio => 'Audio',
            MessageType::Video => 'Video',
            MessageType::Document => 'Documento',
            MessageType::Sticker => 'Sticker',
            MessageType::Location => 'Ubicación',
            MessageType::Contacts => 'Contactos',
            MessageType::Template => 'Plantilla',
            MessageType::Interactive => 'Interactivo',
            MessageType::Reaction => 'Reacción',
            MessageType::Unsupported => 'No compatible',
        };
    }

    /**
     * Same terminology as the conversation screen: a failure of unknown
     * outcome reads "Entrega no confirmada", not "Fallido".
     */
    private function statusLabel(Message $message): string
    {
        if ($message->status === MessageStatus::Failed && $message->error_code === ReconcileStuckDeliveries::CODE) {
            return 'Entrega no confirmada';
        }

        return $message->status->label();
    }
}
