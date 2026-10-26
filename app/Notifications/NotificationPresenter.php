<?php

namespace App\Notifications;

use App\Enums\ConnectionHealth;
use App\Enums\DataTransferStatus;
use App\Enums\Permission;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\DataTransfer;
use App\Models\Department;
use App\Models\TeamChat\TeamParticipant;
use App\Models\User;
use App\Services\Conversations\ConversationAccess;
use App\Support\ProtectedAccounts;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * Builds the visible text of notifications at read time. Stored payloads only
 * hold identifiers; every record is loaded again and access is checked again
 * for the current user, so a notification whose conversation is no longer
 * visible to them shows a generic "Ya no tienes acceso" without details.
 *
 * Names shown (contact, department, colleague, connection) are the same the
 * recipient sees on the screens they can open; message bodies, phone numbers,
 * notes and transfer reasons never appear.
 *
 * @phpstan-type PresentedNotification array{
 *     id: string, kind: string, level: string, title: string, body: string|null, url: string|null,
 *     accessible: bool, occurrences: int, read_at: string|null, created_at: string|null, updated_at: string|null,
 *     conversation_id: int|null, toast: bool
 * }
 */
class NotificationPresenter
{
    /** @var Collection<int, Conversation> */
    private Collection $conversations;

    /** @var array<int, string> */
    private array $userNames = [];

    /**
     * Records referenced by the page being presented, loaded once per page
     * (one query per kind, never one per notification).
     *
     * @var array{departments: array<int, Department>, transfers: array<int, DataTransfer>, connections: array<int, Connection>, threads: array<int, true>}|null
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

        [$title, $body, $url, $accessible] = match ($kind) {
            NotificationKind::ConversationPending => $this->pending($data, $viewer, $occurrences),
            NotificationKind::ConversationAssigned => $this->assigned($data, $viewer),
            NotificationKind::ConversationTransferred => $this->transferred($data, $viewer),
            NotificationKind::ConversationMessage => $this->message($data, $viewer, $occurrences),
            NotificationKind::TeamChatMention, NotificationKind::TeamChatMessage => $this->teamChat($kind, $data, $viewer, $occurrences),
            NotificationKind::ExportFinished, NotificationKind::ImportFinished => $this->transfer($kind, $data, $viewer),
            NotificationKind::ConnectionProblem => $this->connection($data, $viewer),
            NotificationKind::AccessChanged => ['Tu acceso cambió', 'Un administrador actualizó tu rol, permisos, departamentos o el estado de tu cuenta. Algunas secciones o conversaciones pueden haber cambiado.', $this->routeOrNull('dashboard'), true],
            null => ['Notificación', null, null, false],
        };

        return [
            'id' => (string) $notification->id,
            'kind' => $kind !== null ? $kind->value : (string) $notification->getAttribute('kind'),
            'level' => (string) $notification->getAttribute('level'),
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'accessible' => $accessible,
            'occurrences' => $occurrences,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
            'updated_at' => $notification->updated_at?->toIso8601String(),
            'conversation_id' => $accessible && isset($data['conversation_id']) && is_int($data['conversation_id']) ? $data['conversation_id'] : null,
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

        foreach ($notifications as $notification) {
            /** @var array<string, mixed> $data */
            $data = $notification->data;

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
        ];

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
     * @param  array<string, mixed>  $data
     * @return array{string, string|null, string|null, bool}
     */
    private function teamChat(NotificationKind $kind, array $data, User $viewer, int $occurrences): array
    {
        $title = $kind === NotificationKind::TeamChatMention ? 'Te mencionaron en el chat interno' : ($occurrences > 1 ? "{$occurrences} mensajes nuevos en el chat interno" : 'Mensaje nuevo en el chat interno');

        $threadId = $data['thread_id'] ?? null;
        $participates = is_int($threadId) && ($this->related === null
            ? TeamParticipant::query()
                ->where('team_thread_id', $threadId)
                ->where('user_id', $viewer->id)
                ->whereNull('left_at')
                ->exists()
            : isset($this->related['threads'][$threadId]));

        if (! $viewer->holdsPermission(Permission::UseTeamChat) || ! $participates) {
            return [$title, 'Ya no tienes acceso a esta conversación interna.', null, false];
        }

        $author = $this->userName($data['author_id'] ?? null, $viewer);
        $url = $this->routeOrNull('team-chat.threads.show', ['thread' => $threadId]);

        return [$title, $author !== null ? "De {$author}." : null, $url, true];
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

        $title = $failed ? 'No se pudo generar tu exportación' : 'Tu exportación está lista';
        $body = $failed
            ? 'Inténtalo de nuevo o reduce los filtros.'
            : ($transfer->isDownloadable() ? 'Descárgala desde Exportaciones antes de que expire.' : 'El archivo ya expiró.');

        return [$title, $body, $this->routeOrNull('exports.index'), true];
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

        return [
            "Problema en la conexión {$connection->name}",
            'Estado reportado: '.($reported?->label() ?? 'error').'. Estado actual: '.$current->label().'.',
            $this->routeOrNull('connections.show', $connection),
            true,
        ];
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
