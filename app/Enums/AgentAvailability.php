<?php

namespace App\Enums;

/**
 * Operational availability declared by the person. It is independent of
 * having a session open and only decides automatic assignments.
 */
enum AgentAvailability: string
{
    case Available = 'available';
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Disponible',
            self::Unavailable => 'No disponible',
        };
    }
}
