<?php

namespace App\Enums;

/**
 * Curated accent palettes. Each palette maps to design tokens defined in
 * resources/css/app.css for light and dark mode with readable contrast, so the
 * global theme can change without storing arbitrary styles.
 */
enum ThemePalette: string
{
    case Emerald = 'emerald';
    case Teal = 'teal';
    case Sky = 'sky';
    case Indigo = 'indigo';
    case Violet = 'violet';
    case Rose = 'rose';
    case Amber = 'amber';
    case Graphite = 'graphite';

    public const DEFAULT = self::Emerald;

    public function label(): string
    {
        return match ($this) {
            self::Emerald => 'Verde',
            self::Teal => 'Verde azulado',
            self::Sky => 'Azul',
            self::Indigo => 'Índigo',
            self::Violet => 'Violeta',
            self::Rose => 'Rosa',
            self::Amber => 'Ámbar',
            self::Graphite => 'Grafito',
        };
    }

    /**
     * @return list<array{value: string, label: string, is_default: bool}>
     */
    public static function options(): array
    {
        return array_map(fn (self $palette): array => [
            'value' => $palette->value,
            'label' => $palette->label(),
            'is_default' => $palette === self::DEFAULT,
        ], self::cases());
    }
}
