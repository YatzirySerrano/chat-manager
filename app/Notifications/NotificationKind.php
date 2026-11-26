<?php

namespace App\Notifications;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\User;
use App\Support\Features;

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
    case ConversationQueueOverdue = 'conversation.queue-overdue';
    case ConversationAttentionOverdue = 'conversation.attention-overdue';
    case ConversationCompleted = 'conversation.completed';
    case TeamChatMention = 'team-chat.mention';
    case TeamChatMessage = 'team-chat.message';
    case TeamChatDeskRequest = 'team-chat.desk-request';
    case ExportFinished = 'export.finished';
    case ImportFinished = 'import.finished';
    case ConnectionProblem = 'connection.problem';
    case AccessChanged = 'account.access-changed';
    case SlaWarning = 'sla.warning';
    case SlaBreach = 'sla.breach';
    case ChatbotReply = 'chatbot.reply';

    public function label(): string
    {
        return match ($this) {
            self::ConversationPending => 'Nuevas conversaciones pendientes',
            self::ConversationAssigned => 'Conversaciones asignadas a mí',
            self::ConversationTransferred => 'Transferencias recibidas',
            self::ConversationMessage => 'Mensajes nuevos en mis conversaciones',
            self::ConversationQueueOverdue => 'Solicitudes que esperan demasiado en «Pendientes»',
            self::ConversationAttentionOverdue => 'Conversaciones que llevo demasiado tiempo atendiendo',
            self::ConversationCompleted => 'Solicitudes marcadas como completadas',
            self::TeamChatMention => 'Menciones en el chat interno',
            self::TeamChatMessage => 'Mensajes del chat interno',
            self::TeamChatDeskRequest => 'Nuevas solicitudes de la mesa de control',
            self::ExportFinished => 'Archivos preparados',
            self::ImportFinished => 'Importaciones terminadas',
            self::ConnectionProblem => 'Problemas y recuperación de conexiones',
            self::AccessChanged => 'Cambios en mi rol, permisos o departamentos',
            self::SlaWarning => 'Alertas automáticas de SLA: solicitudes por vencer',
            self::SlaBreach => 'Alertas automáticas de SLA: solicitudes vencidas',
            self::ChatbotReply => 'Respuestas automáticas del chatbot',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ConversationPending => 'Cuando llega a «Pendientes» de tus departamentos una conversación que puedes aceptar y estás disponible. Si llegan varias seguidas, recibes un solo aviso por departamento.',
            self::ConversationAssigned => 'Cuando alguien o la distribución automática te asigna una conversación, o vuelve a ti al reabrirse.',
            self::ConversationTransferred => 'Cuando otra persona te transfiere una conversación.',
            self::ConversationQueueOverdue => 'Cuando una solicitud lista para atención lleva en «Pendientes» más del tiempo configurado en Alertas de tiempo (5 minutos por omisión) y hay que tomarla ya.',
            self::ConversationAttentionOverdue => 'Cuando llevas atendiendo una conversación más del tiempo configurado en Alertas de tiempo (15 minutos por omisión) sin resolverla.',
            self::ConversationCompleted => 'Cuando alguien marca una solicitud como completada, para que todo el equipo que puede verla se entere. El tipo de aviso y el mensaje se configuran en Seguimiento de atención → Alertas automáticas.',
            self::ConversationMessage => 'Cuando el cliente escribe en una conversación que atiendes. Se agrupan por conversación hasta que la abres.',
            self::TeamChatMention => 'Cuando un compañero te menciona en el chat interno, aunque hayas silenciado ese chat. Verás quién te escribió y un fragmento breve del mensaje.',
            self::TeamChatMessage => 'Cuando un compañero te escribe en un chat interno que no silenciaste. Verás quién escribió y un fragmento breve; si tienes ese chat abierto a la vista, no aparece el aviso ni suena.',
            self::TeamChatDeskRequest => 'Cuando alguien escribe a la mesa de control y su solicitud queda en espera. La primera persona de la mesa que la acepta la atiende. Si llegan varias seguidas, recibes un solo aviso.',
            self::ExportFinished => 'Cuando un archivo que pediste generar en segundo plano está listo o falló.',
            self::ImportFinished => 'Cuando termina o falla una importación de contactos que iniciaste.',
            self::ConnectionProblem => 'Cuando una conexión (WhatsApp, Messenger, Instagram o chat web) deja de funcionar o Meta rechaza sus credenciales, y cuando vuelve a funcionar.',
            self::AccessChanged => 'Cuando un administrador cambia tu rol, tus permisos o tus departamentos.',
            self::SlaWarning => 'Aviso automático del sistema cuando una solicitud a tu cargo (o, si supervisas, de tu equipo) está por agotar su tiempo máximo de respuesta. No lo envía ninguna persona: es distinto de los recordatorios que escriben los administradores.',
            self::ChatbotReply => 'Cuando el chatbot responde solo a un cliente porque su mensaje tenía una palabra clave, en una conversación que atiendes o que puedes tomar de tu cola.',
            self::SlaBreach => 'Aviso automático del sistema cuando una solicitud a tu cargo (o, si supervisas, de tu equipo) superó su tiempo máximo de respuesta, y sus recordatorios mientras siga sin respuesta. Si supervisas, recibes un solo aviso por departamento.',
        };
    }

    public function level(): NotificationLevel
    {
        return match ($this) {
            self::ConversationPending,
            self::ConversationAssigned,
            self::ConversationTransferred,
            self::ConversationQueueOverdue,
            self::ConversationAttentionOverdue,
            self::TeamChatMention,
            self::TeamChatDeskRequest,
            self::SlaBreach,
            self::ConnectionProblem => NotificationLevel::Urgent,
            default => NotificationLevel::Informative,
        };
    }

    /**
     * Whether a fresh notification of the kind is announced live in the open
     * page with a toast (besides the bell). Urgent kinds ask for an action;
     * a customer writing in a conversation the agent attends, or a colleague
     * writing to them in the internal chat, is the news they wait for, so it
     * is announced too although it stays informative (it does not count as
     * urgent in the bell nor in Web Push).
     */
    public function announcesInPage(): bool
    {
        return $this->level() === NotificationLevel::Urgent
            || $this === self::ConversationMessage
            || $this === self::ConversationCompleted
            || $this === self::TeamChatMessage
            || $this === self::SlaWarning
            || $this === self::ChatbotReply;
    }

    /**
     * Defaults while the user has not saved preferences for the kind. The
     * kinds announced live also sound and show a system notification (the
     * operating system's own alert, e.g. Windows) by default, so every
     * customer or internal chat message reaches the desktop without the user
     * having to turn anything on; a preference the user saved always wins.
     * The browser's own permission is still required (asked once, on the
     * first interaction with the platform, and remembered by the browser).
     *
     * @return array{in_app: bool, browser: bool, sound: bool}
     */
    public function defaults(): array
    {
        return [
            'in_app' => true,
            'browser' => $this->announcesInPage(),
            'sound' => $this->announcesInPage(),
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
            self::ConversationQueueOverdue => $user->holdsPermission(Permission::AcceptConversations) || $user->dataScope() === DataScope::Global,
            self::ConversationAttentionOverdue => $user->holdsPermission(Permission::ViewConversations) && $user->holdsPermission(Permission::ReplyConversations),
            // «Para todos»: anyone who can see conversations learns a request was completed.
            self::ConversationCompleted => $user->holdsPermission(Permission::ViewConversations),
            self::ConversationAssigned,
            self::ConversationTransferred,
            self::ConversationMessage => $user->holdsPermission(Permission::ViewConversations) && $user->holdsPermission(Permission::ReplyConversations),
            self::ChatbotReply => $user->holdsPermission(Permission::ViewConversations)
                && ($user->holdsPermission(Permission::ReplyConversations) || $user->holdsPermission(Permission::AcceptConversations)),
            self::TeamChatMention,
            self::TeamChatMessage => $user->holdsPermission(Permission::UseTeamChat),
            self::TeamChatDeskRequest => $user->holdsPermission(Permission::UseTeamChat) && $user->holdsPermission(Permission::AttendControlDesk),
            self::ExportFinished => $user->holdsPermission(Permission::ExportReports) || $user->holdsPermission(Permission::ExportAuditLog),
            self::ImportFinished => $user->holdsPermission(Permission::ImportContacts),
            self::ConnectionProblem => $user->holdsPermission(Permission::ViewConnections) || $user->holdsPermission(Permission::ManageConnections),
            self::AccessChanged => true,
            // Automatic SLA alerts are phase two (FEATURE_PHASE_TWO).
            self::SlaWarning,
            self::SlaBreach => Features::phaseTwo() && ($user->holdsPermission(Permission::ViewAttention)
                || ($user->holdsPermission(Permission::ViewConversations) && $user->holdsPermission(Permission::ReplyConversations))),
        };
    }
}
