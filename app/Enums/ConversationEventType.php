<?php

namespace App\Enums;

/**
 * Operational events of a conversation. They are append-only and separate
 * from customer messages and internal notes.
 */
enum ConversationEventType: string
{
    case Created = 'created';
    case Accepted = 'accepted';
    case Assigned = 'assigned';
    case AutoAssigned = 'auto_assigned';
    case Unassigned = 'unassigned';
    case Transferred = 'transferred';
    case PutOnHold = 'put_on_hold';
    case Resumed = 'resumed';
    case Closed = 'closed';
    case Reopened = 'reopened';
    case NoteAdded = 'note_added';
    case NoteEdited = 'note_edited';
    case OutboundBlocked = 'outbound_blocked';
    case MessageFailed = 'message_failed';
    case MessageRetried = 'message_retried';
    case ReadinessConfirmed = 'readiness_confirmed';
    case ReadinessReverted = 'readiness_reverted';
    case CategoryChanged = 'category_changed';
    case SlaBreached = 'sla_breached';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Conversación creada',
            self::Accepted => 'Aceptada',
            self::Assigned => 'Asignada',
            self::AutoAssigned => 'Asignada automáticamente',
            self::Unassigned => 'Devuelta a Pendientes',
            self::Transferred => 'Transferida',
            self::PutOnHold => 'Puesta en espera',
            self::Resumed => 'Atención retomada',
            self::Closed => 'Cerrada',
            self::Reopened => 'Reabierta',
            self::NoteAdded => 'Nota interna agregada',
            self::NoteEdited => 'Nota interna editada',
            self::OutboundBlocked => 'Envío bloqueado',
            self::MessageFailed => 'Mensaje no enviado',
            self::MessageRetried => 'Reintento de envío solicitado',
            self::ReadinessConfirmed => 'Solicitud confirmada como completa',
            self::ReadinessReverted => 'Marcada como «Necesita información»',
            self::CategoryChanged => 'Categoría cambiada',
            self::SlaBreached => 'Objetivo de respuesta incumplido',
        };
    }

    /**
     * What the person did, to read after their name in the timeline:
     * «Jennifer cerró la conversación». Null when nobody acts (the system,
     * a missed deadline): then the label is shown alone.
     */
    public function action(): ?string
    {
        return match ($this) {
            self::Created => 'creó la conversación',
            self::Accepted => 'aceptó la conversación',
            self::Assigned => 'asignó la conversación',
            self::Unassigned => 'devolvió la conversación a Pendientes',
            self::Transferred => 'transfirió la conversación',
            self::PutOnHold => 'puso la conversación en espera',
            self::Resumed => 'retomó la atención',
            self::Closed => 'cerró la conversación',
            self::Reopened => 'reabrió la conversación',
            self::NoteAdded => 'agregó una nota interna',
            self::NoteEdited => 'editó una nota interna',
            self::MessageRetried => 'pidió reintentar el envío',
            self::ReadinessConfirmed => 'confirmó la solicitud como completa',
            self::ReadinessReverted => 'marcó la solicitud como «Necesita información»',
            self::CategoryChanged => 'cambió la categoría',
            self::AutoAssigned, self::OutboundBlocked, self::MessageFailed, self::SlaBreached => null,
        };
    }
}
