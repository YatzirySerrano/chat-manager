<?php

namespace App\Enums;

/**
 * Who an attention alert was addressed to, as the sender chose it: one
 * agent, several selected agents, or every agent the sender may alert.
 */
enum AttentionAlertAudience: string
{
    case Individual = 'individual';
    case Selection = 'selection';
    case Everyone = 'everyone';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Individual',
            self::Selection => 'Selección de agentes',
            self::Everyone => 'Todos los agentes',
        };
    }
}
