<?php

namespace App\Enums;

/**
 * Messaging channel a connection reaches customers through.
 */
enum ConnectionChannel: string
{
    case Whatsapp = 'whatsapp';
    case WebChat = 'web_chat';

    public function label(): string
    {
        return match ($this) {
            self::Whatsapp => 'WhatsApp',
            self::WebChat => 'Chat web',
        };
    }

    /**
     * Whether the provider limits free-form replies to the 24 hour customer
     * service window (only WhatsApp does).
     */
    public function usesCustomerServiceWindow(): bool
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
        };
    }

    public function defaultProvider(): ConnectionProvider
    {
        return match ($this) {
            self::Whatsapp => ConnectionProvider::MetaCloudApi,
            self::WebChat => ConnectionProvider::WebWidget,
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
