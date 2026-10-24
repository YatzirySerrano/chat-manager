<?php

namespace App\Enums;

/**
 * State of WhatsApp's 24 hour customer service window of a conversation.
 * Channels without that window (web chat) are always NotApplicable.
 */
enum CustomerWindowStatus: string
{
    /** The customer has not written yet, so no window was ever opened. */
    case None = 'none';
    case Open = 'open';

    /** Still open, but close to expiring. */
    case Expiring = 'expiring';
    case Expired = 'expired';

    /** The channel of the conversation has no customer service window. */
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Sin ventana',
            self::Open => 'Ventana abierta',
            self::Expiring => 'Por vencer',
            self::Expired => 'Ventana vencida',
            self::NotApplicable => 'No aplica',
        };
    }

    public function allowsFreeFormMessages(): bool
    {
        return $this === self::Open || $this === self::Expiring || $this === self::NotApplicable;
    }
}
