<?php

namespace App\Enums;

/**
 * Messaging channel a connection reaches customers through.
 */
enum ConnectionChannel: string
{
    case Whatsapp = 'whatsapp';
    case WebChat = 'web_chat';
    case Messenger = 'messenger';
    case Instagram = 'instagram';
    case Telegram = 'telegram';

    public function label(): string
    {
        return match ($this) {
            self::Whatsapp => 'WhatsApp',
            self::WebChat => 'Chat web',
            self::Messenger => 'Facebook Messenger',
            self::Instagram => 'Instagram',
            self::Telegram => 'Telegram',
        };
    }

    /**
     * Whether the provider limits free-form replies to the 24 hour customer
     * service window (WhatsApp, Messenger and Instagram do; the web chat,
     * which the platform hosts, and Telegram bots do not).
     */
    public function usesCustomerServiceWindow(): bool
    {
        return in_array($this, [self::Whatsapp, self::Messenger, self::Instagram], true);
    }

    /**
     * Whether the channel is operated through a Meta API (Graph / Cloud API)
     * with Meta credentials.
     */
    public function isMeta(): bool
    {
        return in_array($this, [self::Whatsapp, self::Messenger, self::Instagram], true);
    }

    /**
     * Whether a connection of this channel is created from the connection
     * form (a Telegram bot with the token BotFather gave). Messenger and
     * Instagram connections are only created by authorizing the account with
     * Meta (OAuth), never by typing credentials.
     */
    public function isCreatedFromForm(): bool
    {
        return in_array($this, [self::Whatsapp, self::WebChat, self::Telegram], true);
    }

    /**
     * @return list<self>
     */
    public static function formCreatable(): array
    {
        return array_values(array_filter(self::cases(), fn (self $channel): bool => $channel->isCreatedFromForm()));
    }

    /**
     * Whether the customer is identified by a phone number (only WhatsApp;
     * Messenger and Instagram use page-scoped ids).
     */
    public function usesPhoneNumbers(): bool
    {
        return $this === self::Whatsapp;
    }

    /**
     * Whether the channel has provider-approved message templates.
     */
    public function supportsTemplates(): bool
    {
        return $this === self::Whatsapp;
    }

    /**
     * How a contact registered automatically from this channel is recorded.
     */
    public function contactSource(): ContactSource
    {
        return match ($this) {
            self::Whatsapp => ContactSource::Whatsapp,
            self::WebChat => ContactSource::WebChat,
            self::Messenger => ContactSource::Messenger,
            self::Instagram => ContactSource::Instagram,
            self::Telegram => ContactSource::Telegram,
        };
    }

    public function defaultProvider(): ConnectionProvider
    {
        return match ($this) {
            self::Whatsapp => ConnectionProvider::MetaCloudApi,
            self::WebChat => ConnectionProvider::WebWidget,
            self::Messenger => ConnectionProvider::MetaMessenger,
            self::Instagram => ConnectionProvider::MetaInstagram,
            self::Telegram => ConnectionProvider::TelegramBot,
        };
    }

    /**
     * Name given to a contact registered from this channel when the provider
     * did not send one.
     */
    public function fallbackContactName(string $externalId): string
    {
        return match ($this) {
            // A privacy id (LID, "lid:…") is not a phone: never shown as one.
            self::Whatsapp => 'Contacto de WhatsApp '.mb_substr((string) preg_replace('/\D+/', '', $externalId), -4),
            self::WebChat => 'Visitante web '.mb_substr($externalId, -6),
            self::Messenger => 'Usuario de Messenger '.mb_substr($externalId, -6),
            self::Instagram => 'Usuario de Instagram '.mb_substr($externalId, -6),
            self::Telegram => 'Usuario de Telegram '.mb_substr($externalId, -6),
        };
    }

    /**
     * Whether a contact name is still the automatic one of an unnamed
     * WhatsApp sender (the current form or the old "WhatsApp lid:…"), so
     * the profile name may replace it when WhatsApp sends one.
     */
    public static function isAutomaticWhatsAppName(?string $name): bool
    {
        return is_string($name) && preg_match('/^(WhatsApp (lid:)?\d+|Contacto de WhatsApp \d{0,4})$/', trim($name)) === 1;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $channel): array => ['value' => $channel->value, 'label' => $channel->label()], self::cases());
    }
}
