<?php

namespace App\Enums\TeamChat;

/**
 * A one-to-one chat between two employees, a named group, or the «Mesa de
 * control» thread of one requester (one per person, see ControlDesk).
 */
enum TeamThreadType: string
{
    case Direct = 'direct';
    case Group = 'group';
    case Desk = 'desk';

    public function label(): string
    {
        return match ($this) {
            self::Direct => 'Chat privado',
            self::Group => 'Grupo',
            self::Desk => 'Mesa de control',
        };
    }
}
