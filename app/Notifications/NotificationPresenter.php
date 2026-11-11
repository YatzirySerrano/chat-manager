<?php

namespace App\Notifications;

use App\Enums\ConnectionHealth;
use App\Enums\DataTransferStatus;
use App\Enums\Permission;
use App\Enums\ResponseCycleKind;
use App\Enums\TeamChat\TeamAttachmentKind;
use App\Listeners\Notifications\NotifyConnectionProblem;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\DataTransfer;
use App\Models\Department;
use App\Models\ResponseCycle;
use App\Models\TeamChat\TeamAttachment;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamParticipant;
use App\Models\User;
use App\Services\Conversations\ConversationAccess;
use App\Support\ProtectedAccounts;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Builds the visible text of notifications at read time. Stored payloads only
 * hold identifiers; every record is loaded again and access is checked again
 * for the current user, so a notification whose conversation is no longer
 * visible to them shows a generic "Ya no tienes acceso" without details.
 *
 * Names shown (contact, department, colleague, connection) are the same the
 * recipient sees on the screens they can open; customer message bodies, phone
 * numbers, notes and transfer reasons never appear. The only exception is the
 * internal chat: a current participant of the thread sees who wrote and a
 * short, plain-text preview of the message (or what kind of file it was),
 * exactly what the thread list already shows them.
 *
 * @phpstan-type PresentedNotification array{
 *     id: string, kind: string, level: string, title: string, body: string|null, url: string|null,
 *     accessible: bool, occurrences: int, read_at: string|null, created_at: string|null, updated_at: string|null,
 *     conversation_id: int|null, team_thread_id: int|null, toast: bool
 * }
 */
class NotificationPresenter
{
    /** Characters of an internal message shown in its notification. */
    private const TEAM_PREVIEW_LENGTH = 90;

    /** @var Collection<int, Conversation> */
    private Collection $conversations;

    /** @var array<int, string> */
    private array $userNames = [];

    /**
     * Records referenced by the page being presented, loaded once per page
     * (one query per kind, never one per notification).
     *
     * @var array{departments: array<int, Department>, transfers: array<int, DataTransfer>, connections: array<int, Connection>, threads: array<int, true>, team_messages: array<int, TeamMessage>, cycles: array<int, ResponseCycle>}|null
     */
    private ?array $related = null;

    public function __construct(private ConversationAccess $access)
    {
        $this->conversations = new Collection;
    }

    /**
     * @param  iterable<DatabaseNotification>  $notifications
     * @return list<PresentedNotification>
     */
    public function presentMany(iterable $notifications, User $viewer): array
    {
        $notifications = collect($notifications);
        $this->preload($notifications, $viewer);

        $presented = [];

        foreach ($notifications as $notification) {
            $presented[] = $this->present($notification, $viewer);
        }

        return $presented;
    }

    /**
     * @return PresentedNotification
     */
    public function present(DatabaseNotification $notification, User $viewer): array
    {
        /** @var array<string, mixed> $data */
        $data = $notification->data;
        $kind = NotificationKind::tryFrom((string) $notification->getAttribute('kind'));
        $occurrences = (int) ($notification->getAttribute('occurrences') ?? 1);

        $settled = false;

        [$title, $body, $url, $accessible] = match ($kind) {
            NotificationKind::ConversationPending => $this->pending($data, $viewer, $occurrences),
            NotificationKind::ConversationAssigned => $this->assigned($data, $viewer),
            NotificationKind::ConversationTransferred => $this->transferred($data, $viewer),
            NotificationKind::ConversationMessage => $this->message($data, $viewer, $occurrences),
            NotificationKind::TeamChatMention, NotificationKind::TeamChatMessage => $this->teamChat($kind, $data, $viewer, $occurrences),
            NotificationKind::ExportFinished, NotificationKind::ImportFinished => $this->transfer($kind, $data, $viewer),
            NotificationKind::ConnectionProblem => $this->connection($data, $viewer),
            NotificationKind::SlaWarning, NotificationKind::SlaBreach => $this->sla($kind, $data, $viewer, $settled),
            NotificationKind::AccessChanged => ['Tu acceso cambió', 'Un administrador actualizó tu rol, permisos, departamentos o el estado de tu cuenta. Algunas secciones o conversaciones pueden haber cambiado.', $this->routeOrNull('dashboard'), true],
            null => ['Notificación', null, null, false],
        };

        return [
            'id' => (string) $notification->id,
            'kind' => $kind !== null ? $kind->value : (string) $notification->getAttribute('kind'),
            // An SLA alert whose request was already answered or closed no longer asks for an action.
            'level' => $settled ? NotificationLevel::Informative->value : (string) $notification->getAttribute('level'),
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'accessible' => $accessible,
            'occurrences' => $occurrences,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
            'updated_at' => $notification->updated_at?->toIso8601String(),
            'conversation_id' => $accessible && isset($data['conversation_id']) && is_int($data['conversation_id']) ? $data['conversation_id'] : null,
            'team_thread_id' => $accessible && in_array($kind, [NotificationKind::TeamChatMention, NotificationKind::TeamChatMessage], true) && is_int($data['thread_id'] ?? null) ? $data['thread_id'] : null,
            'toast' => $accessible && $kind !== null && $kind->announcesInPage(),
        ];
    }

    /**
     * @param  Collection<int, DatabaseNotification>  $notifications
     */
    private function preload(Collection $notifications, User $viewer): void
    {
        $conversationIds = [];
        $userIds = [];
        $related = ['department_id' => [], 'transfer_id' => [], 'connection_id' => [], 'thread_id' => []];
        $teamMessageIds = [];
        $cycleIds = [];

        foreach ($notifications as $notification) {
            /** @var array<string, mixed> $data */
            $data = $notification->data;

            foreach ((array) ($data['cycle_ids'] ?? []) as $id) {
                if (is_int($id)) {
                    $cycleIds[] = $id;
                }
            }

            foreach ([$data['conversation_id'] ?? null, ...((array) ($data['conversation_ids'] ?? []))] as $id) {
                if (is_int($id)) {
                    $conversationIds[] = $id;
                }
            }

            foreach (['actor_id', 'author_id'] as $key) {
                if (isset($data[$key]) && is_int($data[$key])) {
                    $userIds[] = $data[$key];
                }
            }

            foreach (array_keys($related) as $key) {
                if (isset($data[$key]) && is_int($data[$key])) {
                    $related[$key][] = $data[$key];
                }
            }

            if ($this->isTeamChat($notification) && is_int($data['message_id'] ?? null)) {
                $teamMessageIds[] = $data['message_id'];
            }
        }

        // The visibility of every conversation is decided against the
        // viewer's departments, loaded once instead of once per row.
        $viewer->loadMissing('departments');

        $this->related = [
            'departments' => $related['department_id'] === [] ? [] : Department::query()->whereKey(array_unique($related['department_id']))->get()->keyBy('id')->all(),
            'transfers' => $related['transfer_id'] === [] ? [] : DataTransfer::query()->whereKey(array_unique($related['transfer_id']))->get()->keyBy('id')->all(),
            'connections' => $related['connection_id'] === [] ? [] : Connection::query()->with('webChatWidget')->whereKey(array_unique($related['connection_id']))->get()->keyBy('id')->all(),
            'threads' => $related['thread_id'] === [] ? [] : array_fill_keys(array_map('intval', TeamParticipant::query()
                ->whereIn('team_thread_id', array_unique($related['thread_id']))
                ->where('user_id', $viewer->id)
                ->whereNull('left_at')
                ->pluck('team_thread_id')
                ->all()), true),
            'team_messages' => $teamMessageIds === [] ? [] : $this->teamMessages($teamMessageIds),
            'cycles' => $this->cycles($cycleIds),
        ];

        foreach ($this->related['cycles'] as $cycle) {
            array_push($userIds, ...array_filter([$cycle->assigned_user_id, $cycle->responder_user_id]));
        }

        $this->conversations = Conversation::query()
            ->with(['contact:id,name,profile_name', 'department:id,name'])
            ->whereKey(array_values(array_unique($conversationIds)))
            ->get()
            ->keyBy('id');

        $this->userNames = $userIds === [] ? [] : User::query()
            ->whereKey(array_values(array_unique($userIds)))
            ->pluck('name', 'id')
            ->map(fn (mixed $name): string => (string) $name)
            ->all();
    }

    private function visibleConversation(mixed $id, User $viewer): ?Conversation
    {
        if (! is_int($id)) {
            return null;
        }

        $conversation = $this->conversations->get($id) ?? Conversation::query()->with(['contact:id,name,profile_name', 'department:id,name'])->find($id);

        return $conversation !== null && $this->access->canView($viewer, $conversation) ? $conversation : null;
    }

    private function contactName(Conversation $conversation): string
    {
        $contact = $conversation->contact;

        return $contact === null ? 'un cliente' : ($contact->name !== '' ? $contact->name : ($contact->profile_name ?? 'un cliente'));
    }

    /**
     * @return array{string, string|null, string|null, bool}
     */
    private function noAccess(string $title): array
    {
        return [$title, 'Ya no tienes acceso a esta conversación.', null, false];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{string, string|null, string|null, bool}
     */
    private function pending(array $data, User $viewer, int $occurrences): array
    {
        $visible = collect((array) ($data['conversation_ids'] ?? [$data['conversation_id'] ?? null]))
            ->map(fn (mixed $id): ?Conversation => $this->visibleConversation($id, $viewer))
            ->filter();

        $department = is_int($data['department_id'] ?? null)
            ? ($this->related === null ? Department::query()->find($data['department_id']) : ($this->related['departments'][$data['department_id']] ?? null))
            : null;
        $where = $department !== null ? " en {$department->name}" : ' sin departamento';
        $title = $occurrences > 1 ? "{$occurrences} conversaciones nuevas{$where}" : "Nueva conversación pendiente{$where}";

        /** @var Conversation|null $latest */
        $latest = $visible->first();

        if ($latest === null) {
            return [$title, 'Ya no tienes acceso a estas conversaciones o ya fueron atendidas.', null, false];
        }

        $waiting = $visible->filter(fn (Conversation $conversation): bool => $conversation->assigned_user_id === null)->count();
        $body = $waiting > 0
            ? ($occurrences > 1 ? "{$waiting} siguen en «Pendientes», esperando que alguien las acepte." : "{$this->contactName($latest)} espera ser atendido.")
            : 'Ya fueron atendidas.';
        $url = $occurrences > 1
            ? $this->routeOrNull('conversations.index', ['view' => 'queue'])
            : $this->routeOrNull('conversations.show', $latest);

        return [$title, $body, $url, true];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{string, string|null, string|null, bool}
     */
    private function assigned(array $data, User $viewer): array
    {
        $conversation = $this->visibleConversation($data['conversation_id'] ?? null, $viewer);

        if ($conversation === null) {
            return $this->noAccess('Conversación asignada');
        }

        $actor = $this->userName($data['actor_id'] ?? null, $viewer);
        $title = match ($data['reason'] ?? null) {
            'reopened' => 'Una conversación tuya se reabrió',
            'auto_assigned' => 'Se te asignó una conversación automáticamente',
            default => 'Se te asignó una conversación',
        };
        $body = "Conversación con {$this->contactName($conversation)}"
            .($conversation->department !== null ? " · {$conversation->department->name}" : '')
            .($actor !== null ? " · por {$actor}" : '');

        return [$title, $body, $this->routeOrNull('conversations.show', $conversation), true];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{string, string|null, string|null, bool}
     */
    private function transferred(array $data, User $viewer): array
    {
        $conversation = $this->visibleConversation($data['conversation_id'] ?? null, $viewer);

        if ($conversation === null) {
            return $this->noAccess('Transferencia recibida');
        }

        $actor = $this->userName($data['actor_id'] ?? null, $viewer) ?? 'Un compañero';

        return [
            'Transferencia recibida',
            "{$actor} te transfirió la conversación con {$this->contactName($conversation)}"
                .($conversation->department !== null ? " ({$conversation->department->name})." : '.'),
            $this->routeOrNull('conversations.show', $conversation),
            true,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{string, string|null, string|null, bool}
     */
    private function message(array $data, User $viewer, int $occurrences): array
    {
        $conversation = $this->visibleConversation($data['conversation_id'] ?? null, $viewer);

        if ($conversation === null) {
            return $this->noAccess('Mensaje nuevo');
        }

        $name = $this->contactName($conversation);

        return [
            $occurrences > 1 ? "{$occurrences} mensajes nuevos de {$name}" : "Mensaje nuevo de {$name}",
            'Abre la conversación para leerlo.',
            $this->routeOrNull('conversations.show', $conversation),
            true,
        ];
    }

    /**
     * Who wrote, where, and a short preview of the latest message, only for
     * a current participant of the thread who may still use the internal
     * chat; anyone else gets a generic text without names or content.
     *
     * @param  array<string, mixed>  $data
     * @return array{string, string|null, string|null, bool}
     */
    private function teamChat(NotificationKind $kind, array $data, User $viewer, int $occurrences): array
    {
        $mentioned = $kind === NotificationKind::TeamChatMention;
        $generic = $mentioned ? 'Te mencionaron en el chat interno' : ($occurrences > 1 ? "{$occurrences} mensajes nuevos en el chat interno" : 'Mensaje nuevo en el chat interno');

        $threadId = $data['thread_id'] ?? null;
        $participates = is_int($threadId) && ($this->related === null
            ? TeamParticipant::query()
                ->where('team_thread_id', $threadId)
                ->where('user_id', $viewer->id)
                ->whereNull('left_at')
                ->exists()
            : isset($this->related['threads'][$threadId]));

        if (! $viewer->holdsPermission(Permission::UseTeamChat) || ! $participates) {
            return [$generic, 'Ya no tienes acceso a esta conversación interna.', null, false];
        }

        $url = $this->routeOrNull('team-chat.threads.show', ['thread' => $threadId]);
        $author = $this->userName($data['author_id'] ?? null, $viewer);
        $message = $this->teamMessage($data['message_id'] ?? null);

        if ($message === null || $message->team_thread_id !== $threadId || $author === null) {
            return [$generic, $author !== null ? "De {$author}." : null, $url, true];
        }

        $group = $message->thread !== null && $message->thread->isGroup() ? (string) $message->thread->name : null;
        $preview = $this->teamMessagePreview($message);

        $title = match (true) {
            $mentioned && $occurrences > 1 => $group !== null ? "Te mencionaron {$occurrences} veces en {$group}" : "{$author} te mencionó {$occurrences} veces",
            $mentioned => $group !== null ? "{$author} te mencionó en {$group}" : "{$author} te mencionó",
            $occurrences > 1 => $group !== null ? "{$occurrences} mensajes nuevos en {$group}" : "{$occurrences} mensajes nuevos de {$author}",
            default => $group !== null ? "{$author} en {$group}" : "Mensaje nuevo de {$author}",
        };

        // A folded group notification may gather several authors: the
        // preview says who wrote the latest message.
        $body = $group !== null && $occurrences > 1 && $preview !== null ? "{$author}: {$preview}" : $preview;

        return [$title, $body, $url, true];
    }

    /**
     * Plain-text preview of an internal message: its text squished and
     * shortened, or what was sent when it only carries files.
     */
    private function teamMessagePreview(TeamMessage $message): ?string
    {
        if ($message->isDeleted()) {
            return 'Se eliminó este mensaje.';
        }

        if (filled($message->body)) {
            return Str::limit(Str::squish((string) $message->body), self::TEAM_PREVIEW_LENGTH);
        }

        $kinds = $message->attachments->map(fn (TeamAttachment $attachment): TeamAttachmentKind => $attachment->kind)->unique();
        $count = $message->attachments->count();

        if ($count === 0) {
            return null;
        }

        if ($kinds->count() > 1) {
            return "Envió {$count} archivos";
        }

        return match ($kinds->first()) {
            TeamAttachmentKind::Image => $count === 1 ? 'Envió una imagen' : "Envió {$count} imágenes",
            TeamAttachmentKind::Video => $count === 1 ? 'Envió un video' : "Envió {$count} videos",
            TeamAttachmentKind::Audio => $count === 1 ? 'Envió un audio' : "Envió {$count} audios",
            default => $count === 1 ? 'Envió un documento' : "Envió {$count} documentos",
        };
    }

    private function teamMessage(mixed $id): ?TeamMessage
    {
        if (! is_int($id)) {
            return null;
        }

        return $this->related === null ? ($this->teamMessages([$id])[$id] ?? null) : ($this->related['team_messages'][$id] ?? null);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, TeamMessage>
     */
    private function teamMessages(array $ids): array
    {
        return TeamMessage::query()
            ->with(['thread:id,type,name', 'attachments:id,team_message_id,kind'])
            ->whereKey(array_values(array_unique($ids)))
            ->get()
            ->keyBy('id')
            ->all();
    }

    private function isTeamChat(DatabaseNotification $notification): bool
    {
        return in_array(NotificationKind::tryFrom((string) $notification->getAttribute('kind')), [NotificationKind::TeamChatMention, NotificationKind::TeamChatMessage], true);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{string, string|null, string|null, bool}
     */
    private function transfer(NotificationKind $kind, array $data, User $viewer): array
    {
        $transfer = is_int($data['transfer_id'] ?? null)
            ? ($this->related === null ? DataTransfer::query()->find($data['transfer_id']) : ($this->related['transfers'][$data['transfer_id']] ?? null))
            : null;
        $isImport = $kind === NotificationKind::ImportFinished;

        if ($transfer === null || $transfer->user_id !== $viewer->id) {
            return [$isImport ? 'Importación terminada' : 'Exportación terminada', 'El archivo ya no está disponible.', null, false];
        }

        $failed = $transfer->status === DataTransferStatus::Failed;

        if ($isImport) {
            return [
                $failed ? 'La importación de contactos falló' : 'La importación de contactos terminó',
                $failed ? 'Revisa el detalle para conocer el motivo.' : 'Revisa el resumen y el reporte de resultados.',
                $this->routeOrNull('contacts.import.show', ['transfer' => $transfer->id]),
                true,
            ];
        }

        // A ready file is downloaded straight from the notification (the
        // download route checks owner, permission and expiration again); a
        // failed or expired one has nothing to open and says what to do.
        if ($failed) {
            return ['No se pudo generar tu exportación', 'Inténtalo de nuevo desde el módulo o reduce los filtros.', null, true];
        }

        if (! $transfer->isDownloadable()) {
            return ['Tu exportación está lista', 'El archivo ya expiró. Vuelve a exportarlo desde el módulo.', null, true];
        }

        return [
            'Tu exportación está lista',
            'Descárgala desde esta notificación antes de que expire.',
            $this->routeOrNull('exports.transfers.download', ['transfer' => $transfer->id]),
            true,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{string, string|null, string|null, bool}
     */
    private function connection(array $data, User $viewer): array
    {
        if (! NotificationKind::ConnectionProblem->concerns($viewer)) {
            return ['Problema en una conexión', 'Ya no tienes acceso a las conexiones.', null, false];
        }

        $connection = is_int($data['connection_id'] ?? null)
            ? ($this->related === null ? Connection::query()->find($data['connection_id']) : ($this->related['connections'][$data['connection_id']] ?? null))
            : null;

        if ($connection === null) {
            return ['Problema en una conexión', 'La conexión ya no existe.', null, false];
        }

        $reported = ConnectionHealth::tryFrom((string) ($data['health'] ?? ''));
        $current = $connection->health();
        $url = $this->routeOrNull('connections.show', $connection);
        // Straight to the diagnostics of the connection.
        $url = $url === null ? null : $url.'#diagnostico';

        // The text follows what the connection is today, so a problem that
        // was solved is never presented as current.
        if (in_array($current, NotifyConnectionProblem::PROBLEM_STATES, true)) {
            return [
                "Problema en la conexión {$connection->name}",
                'Estado reportado: '.($reported !== null && in_array($reported, NotifyConnectionProblem::PROBLEM_STATES, true) ? $reported->label() : $current->label()).'. Estado actual: '.$current->label().'. Abre el diagnóstico para ver qué hacer.',
                $url,
                true,
            ];
        }

        if (($data['recovered'] ?? false) === true) {
            return [
                "La conexión {$connection->name} se recuperó",
                'Hay evidencia de que volvió a funcionar. Estado actual: '.$current->label().'.',
                $url,
                true,
            ];
        }

        return [
            "Aviso anterior de la conexión {$connection->name}",
            'Se reportó: '.($reported?->label() ?? 'error').'. Ya no está en ese estado; estado actual: '.$current->label().'.',
            $url,
            true,
        ];
    }

    /**
     * Automatic SLA alert («por vencer» or «vencida»). The text follows what
     * the request is like today: one that was answered or closed since is
     * presented as resolved (`$settled`), never as still waiting. An
     * aggregated notification (supervision) counts the requests it gathers
     * and how many still wait.
     *
     * @param  array<string, mixed>  $data
     * @return array{string, string|null, string|null, bool}
     */
    private function sla(NotificationKind $kind, array $data, User $viewer, bool &$settled): array
    {
        $breach = $kind === NotificationKind::SlaBreach;
        $generic = $breach ? 'Solicitud vencida (SLA)' : 'Solicitud por vencer (SLA)';
        $cycleIds = array_values(array_filter((array) ($data['cycle_ids'] ?? [$data['cycle_id'] ?? null]), 'is_int'));
        $loaded = $this->related === null ? $this->cycles($cycleIds) : $this->related['cycles'];

        /** @var list<array{ResponseCycle, Conversation}> $visible */
        $visible = [];

        foreach ($cycleIds as $cycleId) {
            $cycle = $loaded[$cycleId] ?? null;
            $conversation = $cycle === null ? null : $this->visibleConversation($cycle->conversation_id, $viewer);

            if ($cycle !== null && $conversation !== null) {
                $visible[] = [$cycle, $conversation];
            }
        }

        if ($visible === []) {
            $settled = true;

            return [$generic, 'Ya no tienes acceso a esta conversación.', null, false];
        }

        $waiting = array_values(array_filter($visible, fn (array $pair): bool => $pair[0]->isActive()));
        $settled = $waiting === [];
        $supervisor = ($data['supervisor'] ?? false) === true;

        if (count($visible) > 1) {
            $department = is_int($data['department_id'] ?? null) ? ($this->related['departments'][$data['department_id']] ?? Department::query()->find($data['department_id'])) : null;
            $where = $department !== null ? " en {$department->name}" : '';
            $total = count($visible);
            $pending = count($waiting);

            return [
                $breach ? "{$total} solicitudes vencieron{$where}" : "{$total} solicitudes por vencer{$where}",
                $pending === 0
                    ? 'Aviso automático de SLA. Ya fueron respondidas o cerradas.'
                    : 'Aviso automático de SLA. '.($pending === 1 ? '1 sigue' : "{$pending} siguen").' sin respuesta. Revisa el monitoreo de SLA.',
                $this->routeOrNull('attention.index', ['tab' => 'sla', 'sla_state' => $breach ? 'breached' : 'warning']),
                true,
            ];
        }

        [$cycle, $conversation] = $visible[0];
        $name = $this->contactName($conversation);
        $objective = $cycle->kind === ResponseCycleKind::FirstResponse ? 'la primera respuesta' : 'la respuesta';
        $url = $this->routeOrNull('conversations.show', $conversation);
        $reminder = $breach && is_int($data['reminder'] ?? null) && $data['reminder'] > 0 ? "Recordatorio {$data['reminder']}. " : '';

        if ($cycle->outcome === ResponseCycle::OUTCOME_ANSWERED) {
            $responder = $this->userName($cycle->responder_user_id, $viewer);
            $late = $cycle->breached_at !== null;

            return [
                ($late ? 'Solicitud vencida ya respondida: ' : 'Solicitud respondida a tiempo: ').$name,
                'Aviso automático de SLA. '.($responder !== null ? "{$responder} respondió" : 'Ya se respondió').($late ? ' después del plazo.' : ' antes de que venciera el plazo.'),
                $url,
                true,
            ];
        }

        if (! $cycle->isActive()) {
            return [
                ($cycle->breached_at !== null ? 'Solicitud vencida ya cerrada: ' : 'Solicitud cerrada: ').$name,
                'Aviso automático de SLA. La conversación se cerró sin respuesta'.($cycle->breached_at !== null ? ' después del plazo.' : '.'),
                $url,
                true,
            ];
        }

        $responsible = $this->userName($cycle->assigned_user_id, $viewer);
        $who = $supervisor ? ($responsible !== null ? " Responsable: {$responsible}." : ' Está en la cola, sin responsable.') : '';

        if ($cycle->breached_at !== null) {
            return [
                "Solicitud vencida: {$name}",
                "{$reminder}Aviso automático de SLA. Se agotó el tiempo máximo para {$objective} y el cliente sigue esperando.{$who}",
                $url,
                true,
            ];
        }

        return [
            "Solicitud por vencer: {$name}",
            "Aviso automático de SLA. Está por agotarse el tiempo máximo para {$objective}.{$who}",
            $url,
            true,
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, ResponseCycle>
     */
    private function cycles(array $ids): array
    {
        return $ids === [] ? [] : ResponseCycle::query()->whereKey(array_values(array_unique($ids)))->get()->keyBy('id')->all();
    }

    /**
     * The person's name as the viewer may see it: a protected account (root
     * role) is the system administration for anyone else.
     */
    private function userName(mixed $id, User $viewer): ?string
    {
        if (! is_int($id)) {
            return null;
        }

        if (app(ProtectedAccounts::class)->hides($id, $viewer)) {
            return ProtectedAccounts::SYSTEM_NAME;
        }

        return $this->userNames[$id] ??= (string) User::query()->whereKey($id)->value('name');
    }

    /**
     * @param  mixed  $parameters
     */
    private function routeOrNull(string $name, $parameters = []): ?string
    {
        return Route::has($name) ? route($name, $parameters) : null;
    }
}
