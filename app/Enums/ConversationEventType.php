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
        };
    }
}
