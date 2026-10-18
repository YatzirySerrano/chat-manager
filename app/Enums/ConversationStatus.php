<?php

namespace App\Enums;

enum ConversationStatus: string
{
    /** Waiting in a department queue without an assigned agent. */
    case Pending = 'pending';

    /** Assigned to an agent and being attended. */
    case Open = 'open';

    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Open => 'Activa',
            self::Closed => 'Cerrada',
        };
    }
}
