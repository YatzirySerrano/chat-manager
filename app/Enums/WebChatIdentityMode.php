<?php

namespace App\Enums;

/**
 * Whether web chat visitors may, or must, be identified by the host site with
 * a signed identity.
 */
enum WebChatIdentityMode: string
{
    case Anonymous = 'anonymous';
    case Optional = 'optional';
    case Required = 'required';

    public function label(): string
    {
        return match ($this) {
            self::Anonymous => 'Solo visitantes anónimos',
            self::Optional => 'Identificación opcional',
            self::Required => 'Identificación obligatoria',
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
