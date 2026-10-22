<?php

namespace App\Enums\TeamChat;

/**
 * A one-to-one chat between two employees, or a named group.
 */
enum TeamThreadType: string
{
    case Direct = 'direct';
    case Group = 'group';

    public function label(): string
    {
        return match ($this) {
            self::Direct => 'Chat privado',
            self::Group => 'Grupo',
        };
    }
}
