<?php

namespace App\Enums;

/**
 * API used to operate a connection. WhatsappWeb is the WhatsApp Web
 * protocol of a phone linked by QR (docs/whatsapp-web-qr.md): enabled per
 * server and never the default. TelegramBot is a Telegram bot operated
 * through the Bot API with the token BotFather gave.
 */
enum ConnectionProvider: string
{
    case MetaCloudApi = 'meta_cloud_api';
    case WebWidget = 'web_widget';
    case MetaMessenger = 'meta_messenger';
    case MetaInstagram = 'meta_instagram';
    case WhatsappWeb = 'whatsapp_web';
    case TelegramBot = 'telegram_bot';

    public function label(): string
    {
        return match ($this) {
            self::MetaCloudApi => 'WhatsApp Cloud API (Meta)',
            self::WebWidget => 'Widget de chat web',
            self::MetaMessenger => 'Messenger Platform (Meta)',
            self::MetaInstagram => 'Instagram Messaging API (Meta)',
            self::WhatsappWeb => 'WhatsApp Web (QR)',
            self::TelegramBot => 'Telegram Bot API',
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
            self::TelegramBot => ConnectionChannel::Telegram,
        };
    }

    /**
     * Whether the connection is operated through a Meta API with Meta
     * credentials (every provider except the web widget, WhatsApp Web and
     * the Telegram Bot API).
     */
    public function usesMetaApi(): bool
    {
        return ! in_array($this, [self::WebWidget, self::WhatsappWeb, self::TelegramBot], true);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $provider): array => ['value' => $provider->value, 'label' => $provider->label()], self::cases());
    }
}
