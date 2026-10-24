<?php

namespace App\Enums;

/**
 * Capabilities an integration API token may be granted.
 */
enum ApiAbility: string
{
    case ConversationsRead = 'conversations:read';
    case MessagesRead = 'messages:read';
    case MessagesSend = 'messages:send';

    public function label(): string
    {
        return match ($this) {
            self::ConversationsRead => 'Consultar conversaciones',
            self::MessagesRead => 'Consultar mensajes',
            self::MessagesSend => 'Enviar mensajes',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $ability): array => ['value' => $ability->value, 'label' => $ability->label()], self::cases());
    }
}
