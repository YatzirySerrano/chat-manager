<?php

namespace App\Enums;

/**
 * Where a conversation comes from: attended on the platform (live) or
 * imported from a Whaticket export (history, never operational).
 */
enum ConversationOrigin: string
{
    case Live = 'live';
    case Whaticket = 'whaticket';

    public function label(): string
    {
        return match ($this) {
            self::Live => 'Atendida en Chat Manager',
            self::Whaticket => 'Importada de Whaticket',
        };
    }
}
