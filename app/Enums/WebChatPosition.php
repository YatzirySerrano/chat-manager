<?php

namespace App\Enums;

/**
 * Corner of the host page where the web chat launcher is shown.
 */
enum WebChatPosition: string
{
    case Right = 'right';
    case Left = 'left';

    public function label(): string
    {
        return match ($this) {
            self::Right => 'Derecha',
            self::Left => 'Izquierda',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $position): array => ['value' => $position->value, 'label' => $position->label()], self::cases());
    }
}
