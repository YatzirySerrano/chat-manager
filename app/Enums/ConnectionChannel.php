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

    public function label(): string
    {
        return match ($this) {
            self::Whatsapp => 'WhatsApp',
            self::WebChat => 'Chat web',
            self::Messenger => 'Facebook Messenger',
            self::Instagram => 'Instagram',
        };
    }

    /**
     * Whether the provider limits free-form replies to the 24 hour customer
     * service window (WhatsApp, Messenger and Instagram do; the web chat,
     * which the platform hosts, does not).
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
     * form. Messenger and Instagram connections are only created by
     * authorizing the account with Meta (OAuth), never by typing credentials.
     */
    public function isCreatedFromForm(): bool
    {
        return in_array($this, [self::Whatsapp, self::WebChat], true);
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
        };
    }

    public function defaultProvider(): ConnectionProvider
    {
        return match ($this) {
            self::Whatsapp => ConnectionProvider::MetaCloudApi,
            self::WebChat => ConnectionProvider::WebWidget,
            self::Messenger => ConnectionProvider::MetaMessenger,
            self::Instagram => ConnectionProvider::MetaInstagram,
        };
    }

    /**
     * Name given to a contact registered from this channel when the provider
     * did not send one.
     */
    public function fallbackContactName(string $externalId): string
    {
        return match ($this) {
            self::Whatsapp => "WhatsApp {$externalId}",
            self::WebChat => 'Visitante web '.mb_substr($externalId, -6),
            self::Messenger => 'Usuario de Messenger '.mb_substr($externalId, -6),
            self::Instagram => 'Usuario de Instagram '.mb_substr($externalId, -6),
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
