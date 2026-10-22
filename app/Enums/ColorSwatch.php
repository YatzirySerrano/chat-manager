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
     * Spanish name of the swatch (as in resources/js/lib/swatches.ts).
     */
    public function label(): string
    {
        return match ($this) {
            self::Slate => 'Pizarra',
            self::Blue => 'Azul',
            self::Teal => 'Turquesa',
            self::Emerald => 'Esmeralda',
            self::Amber => 'Ámbar',
            self::Orange => 'Naranja',
            self::Rose => 'Rosa',
            self::Violet => 'Violeta',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
