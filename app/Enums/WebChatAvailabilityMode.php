<?php

namespace App\Enums;

/**
 * When the web chat shows itself online: always, or following the business
 * hours of the connection's default department.
 */
enum WebChatAvailabilityMode: string
{
    case Always = 'always';
    case BusinessHours = 'business_hours';

    public function label(): string
    {
        return match ($this) {
            self::Always => 'Siempre en línea',
            self::BusinessHours => 'Según el horario de atención',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $mode): array => ['value' => $mode->value, 'label' => $mode->label()], self::cases());
    }
}
