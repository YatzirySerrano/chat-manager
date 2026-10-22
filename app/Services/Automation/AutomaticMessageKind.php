<?php

namespace App\Services\Automation;

/**
 * Automatic messages the platform may send to a customer. Welcome and away
 * share the "greeting" slot of a conversation cycle: at most one of them is
 * ever sent per cycle.
 */
enum AutomaticMessageKind: string
{
    case Welcome = 'welcome';
    case Away = 'away';
    case Farewell = 'farewell';

    /**
     * Idempotency slot: one message per (conversation, slot, cycle).
     */
    public function slot(): string
    {
        return match ($this) {
            self::Welcome, self::Away => 'greeting',
            self::Farewell => 'farewell',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Welcome => 'Bienvenida',
            self::Away => 'Fuera de horario',
            self::Farewell => 'Despedida',
        };
    }
}
