<?php

namespace App\Enums\TeamChat;

/**
 * State of the current request of a «Mesa de control» thread.
 *
 * - Pending: the requester wrote and nobody accepted it yet (waiting list).
 * - Open: an auditor accepted it; it stays hers, also with unread
 *   messages, until she marks it complete.
 * - Closed: marked complete. A new message of the requester puts it back
 *   on the waiting list (pending, unassigned).
 */
enum TeamDeskStatus: string
{
    case Pending = 'pending';
    case Open = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En espera',
            self::Open => 'En atención',
            self::Closed => 'Completada',
        };
    }
}
