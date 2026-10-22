<?php

namespace App\Notifications;

use App\Enums\Permission;
use App\Models\User;

/**
 * Every kind of in-app notification, with its level, its default delivery
 * preferences and who may ever receive it (the preferences page only lists
 * the kinds relevant to the user).
 */
enum NotificationKind: string
{
    case ConversationPending = 'conversation.pending';
    case ConversationAssigned = 'conversation.assigned';
    case ConversationTransferred = 'conversation.transferred';
    case ConversationMessage = 'conversation.message';
    case TeamChatMention = 'team-chat.mention';
    case TeamChatMessage = 'team-chat.message';
    case ExportFinished = 'export.finished';
    case ImportFinished = 'import.finished';
    case ConnectionProblem = 'connection.problem';
    case AccessChanged = 'account.access-changed';

    public function label(): string
    {
        return match ($this) {
            self::ConversationPending => 'Nuevas conversaciones pendientes',
            self::ConversationAssigned => 'Conversaciones asignadas a mí',
            self::ConversationTransferred => 'Transferencias recibidas',
            self::ConversationMessage => 'Mensajes nuevos en mis conversaciones',
            self::TeamChatMention => 'Menciones en el chat interno',
            self::TeamChatMessage => 'Mensajes del chat interno',
            self::ExportFinished => 'Exportaciones terminadas',
            self::ImportFinished => 'Importaciones terminadas',
            self::ConnectionProblem => 'Problemas en conexiones de WhatsApp',
            self::AccessChanged => 'Cambios en mi rol, permisos o departamentos',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ConversationPending => 'Cuando llega a la cola de tus departamentos una conversación que puedes aceptar y estás disponible. Las ráfagas se agrupan en un solo aviso por departamento.',
            self::ConversationAssigned => 'Cuando alguien o la distribución automática te asigna una conversación, o vuelve a ti al reabrirse.',
            self::ConversationTransferred => 'Cuando otra persona te transfiere una conversación.',
            self::ConversationMessage => 'Cuando el cliente escribe en una conversación que atiendes. Se agrupan por conversación hasta que la abres.',
            self::TeamChatMention => 'Cuando un compañero te menciona en el chat interno.',
            self::TeamChatMessage => 'Cuando recibes un mensaje en una conversación interna.',
            self::ExportFinished => 'Cuando un archivo que pediste generar en segundo plano está listo o falló.',
            self::ImportFinished => 'Cuando termina o falla una importación de contactos que iniciaste.',
            self::ConnectionProblem => 'Cuando una conexión de WhatsApp deja de funcionar o Meta rechaza sus credenciales.',
            self::AccessChanged => 'Cuando un administrador cambia tu rol, tus permisos o tus departamentos.',
        };
    }

    public function level(): NotificationLevel
    {
        return match ($this) {
            self::ConversationPending,
            self::ConversationAssigned,
            self::ConversationTransferred,
            self::TeamChatMention,
            self::ConnectionProblem => NotificationLevel::Urgent,
            default => NotificationLevel::Informative,
        };
    }

    /**
     * Defaults while the user has not saved preferences for the kind.
     * Browser notifications are always off by default: they need an explicit
     * opt-in and the browser's permission.
     *
     * @return array{in_app: bool, browser: bool, sound: bool}
     */
    public function defaults(): array
    {
        return [
            'in_app' => true,
            'browser' => false,
            'sound' => $this->level() === NotificationLevel::Urgent,
        ];
    }

    /**
     * Whether the kind can concern the user at all, given their permissions
     * today. Delivery checks the concrete record again; this only decides
     * what the preferences page offers.
     */
    public function concerns(User $user): bool
    {
        return match ($this) {
            self::ConversationPending => $user->holdsPermission(Permission::AcceptConversations),
            self::ConversationAssigned,
            self::ConversationTransferred,
            self::ConversationMessage => $user->holdsPermission(Permission::ViewConversations) && $user->holdsPermission(Permission::ReplyConversations),
            self::TeamChatMention,
            self::TeamChatMessage => $user->holdsPermission(Permission::UseTeamChat),
            self::ExportFinished => $user->holdsPermission(Permission::ExportReports) || $user->holdsPermission(Permission::ExportAuditLog),
            self::ImportFinished => $user->holdsPermission(Permission::ImportContacts),
            self::ConnectionProblem => $user->holdsPermission(Permission::ViewConnections) || $user->holdsPermission(Permission::ManageConnections),
            self::AccessChanged => true,
        };
    }
}
