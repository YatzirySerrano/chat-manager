<?php

namespace App\Enums;

/**
 * How the contact record was created (not how the person found the company;
 * see ContactOriginType for that).
 */
enum ContactSource: string
{
    case Manual = 'manual';
    /** Registered automatically from an inbound provider message. */
    case Whatsapp = 'whatsapp';
    case Import = 'import';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Registro manual',
            self::Whatsapp => 'Automático (WhatsApp)',
            self::Import => 'Importación',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $source): array => ['value' => $source->value, 'label' => $source->label()], self::cases());
    }
}
