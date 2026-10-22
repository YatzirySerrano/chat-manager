<?php

namespace App\Enums\TeamChat;

/**
 * Role of a person inside a group. Group administrators rename the group and
 * manage its participants; direct chats only have members.
 */
enum TeamParticipantRole: string
{
    case Member = 'member';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Member => 'Participante',
            self::Admin => 'Administrador del grupo',
        };
    }
}
