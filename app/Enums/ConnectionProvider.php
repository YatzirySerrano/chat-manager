<?php

namespace App\Enums;

/**
 * Official API used to operate a connection.
 */
enum ConnectionProvider: string
{
    case MetaCloudApi = 'meta_cloud_api';

    public function label(): string
    {
        return match ($this) {
            self::MetaCloudApi => 'WhatsApp Cloud API (Meta)',
        };
    }

    public function channel(): ConnectionChannel
    {
        return match ($this) {
            self::MetaCloudApi => ConnectionChannel::Whatsapp,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $provider): array => ['value' => $provider->value, 'label' => $provider->label()], self::cases());
    }
}
