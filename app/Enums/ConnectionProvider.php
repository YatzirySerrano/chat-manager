<?php

namespace App\Enums;

/**
 * Official API used to operate a connection.
 */
enum ConnectionProvider: string
{
    case MetaCloudApi = 'meta_cloud_api';
    case WebWidget = 'web_widget';
    case MetaMessenger = 'meta_messenger';
    case MetaInstagram = 'meta_instagram';

    public function label(): string
    {
        return match ($this) {
            self::MetaCloudApi => 'WhatsApp Cloud API (Meta)',
            self::WebWidget => 'Widget de chat web',
            self::MetaMessenger => 'Messenger Platform (Meta)',
            self::MetaInstagram => 'Instagram Messaging API (Meta)',
        };
    }

    public function channel(): ConnectionChannel
    {
        return match ($this) {
            self::MetaCloudApi => ConnectionChannel::Whatsapp,
            self::WebWidget => ConnectionChannel::WebChat,
            self::MetaMessenger => ConnectionChannel::Messenger,
            self::MetaInstagram => ConnectionChannel::Instagram,
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
