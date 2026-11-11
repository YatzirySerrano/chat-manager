<?php

namespace App\Enums;

/**
 * Which objective a response cycle is measured against: the first answer of
 * the team to a conversation, or the answer to a later customer message.
 */
enum ResponseCycleKind: string
{
    case FirstResponse = 'first_response';
    case Response = 'response';

    public function label(): string
    {
        return match ($this) {
            self::FirstResponse => 'Primera respuesta',
            self::Response => 'Respuesta',
        };
    }
}
