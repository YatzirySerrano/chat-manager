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
    /** Registered automatically from a web chat visitor message. */
    case WebChat = 'web_chat';
    /** Registered automatically from a Facebook Messenger message. */
    case Messenger = 'messenger';
    /** Registered automatically from an Instagram direct message. */
    case Instagram = 'instagram';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Agregado a mano',
            self::Whatsapp => 'Creado al escribir por WhatsApp',
            self::Import => 'Importación',
            self::WebChat => 'Creado al escribir por Chat web',
            self::Messenger => 'Creado al escribir por Messenger',
            self::Instagram => 'Creado al escribir por Instagram',
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
