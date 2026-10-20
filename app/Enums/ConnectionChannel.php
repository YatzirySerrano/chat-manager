<?php

namespace App\Enums;

/**
 * Messaging channel a connection reaches customers through.
 */
enum ConnectionChannel: string
{
    case Whatsapp = 'whatsapp';

    public function label(): string
    {
        return match ($this) {
            self::Whatsapp => 'WhatsApp',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $channel): array => ['value' => $channel->value, 'label' => $channel->label()], self::cases());
    }
}
