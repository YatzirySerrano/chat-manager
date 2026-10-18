<?php

namespace App\Enums;

enum ColorSwatch: string
{
    case Slate = 'slate';
    case Blue = 'blue';
    case Teal = 'teal';
    case Emerald = 'emerald';
    case Amber = 'amber';
    case Orange = 'orange';
    case Rose = 'rose';
    case Violet = 'violet';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
