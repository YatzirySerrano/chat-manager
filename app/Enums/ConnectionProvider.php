<?php

namespace App\Enums;

/**
 * API used to operate a connection. Every provider is an official API
 * except WhatsappWeb, the WhatsApp Web protocol of a phone linked by QR
 * (docs/whatsapp-web-qr.md): unofficial, opt-in and never the default.
 */
enum ConnectionProvider: string
{
    case MetaCloudApi = 'meta_cloud_api';
    case WebWidget = 'web_widget';
    case MetaMessenger = 'meta_messenger';
    case MetaInstagram = 'meta_instagram';
    case WhatsappWeb = 'whatsapp_web';

    public function label(): string
    {
        return match ($this) {
            self::MetaCloudApi => 'WhatsApp Cloud API (Meta)',
            self::WebWidget => 'Widget de chat web',
            self::MetaMessenger => 'Messenger Platform (Meta)',
            self::MetaInstagram => 'Instagram Messaging API (Meta)',
            self::WhatsappWeb => 'WhatsApp Web (QR)',
        };
    }

    public function channel(): ConnectionChannel
    {
        return match ($this) {
            self::MetaCloudApi => ConnectionChannel::Whatsapp,
            self::WebWidget => ConnectionChannel::WebChat,
            self::MetaMessenger => ConnectionChannel::Messenger,
            self::MetaInstagram => ConnectionChannel::Instagram,
            self::WhatsappWeb => ConnectionChannel::Whatsapp,
        };
    }

    /**
     * Whether the connection is operated through a Meta API with Meta
     * credentials (every provider except the web widget and WhatsApp Web).
     */
    public function usesMetaApi(): bool
    {
        return ! in_array($this, [self::WebWidget, self::WhatsappWeb], true);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $provider): array => ['value' => $provider->value, 'label' => $provider->label()], self::cases());
    }
}
