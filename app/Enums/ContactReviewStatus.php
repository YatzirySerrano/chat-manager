<?php

namespace App\Enums;

enum ContactReviewStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Pendiente',
            self::Resolved => 'Resuelto',
        };
    }
}
