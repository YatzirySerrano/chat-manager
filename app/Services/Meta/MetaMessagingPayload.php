<?php

namespace App\Services\Meta;

use App\Enums\ConnectionChannel;
use App\Enums\MessageType;
use App\Support\InboundMessage;
use App\Support\InboundSender;
use App\Support\ProviderReferral;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Reads an authenticated Messenger (object "page") or Instagram (object
 * "instagram") webhook body: entry[].messaging[] items become
 * MetaMessagingEvent values, sorted by timestamp (Meta does not guarantee
 * their order).
 *
 * - Customer messages keep Meta's "mid" as provider message id (normalized
 *   to at most 128 characters, see messageId()). A message with several
 *   attachments becomes one stored message per attachment ("mid", then
 *   "mid#2"...), the first one carrying the text.
 * - Postbacks (buttons) become text messages with the button title.
 * - Echoes, deliveries and reads are returned for status handling;
 *   reactions, optins, referrals without message and unknown items are
 *   returned as ignored. Timestamps later than now are capped at now.
 *
 * Signature verification belongs to the webhook endpoint.
 */
class MetaMessagingPayload
{
    /** Longest provider id stored as is; longer ids are stored as their SHA-256. */
    public const MAX_MESSAGE_ID_LENGTH = 128;

    /**
     * Channel of a webhook object, or null when it is not a Messenger or Instagram body.
     */
    public static function channelOf(mixed $object): ?ConnectionChannel
    {
        return match ($object) {
            'page' => ConnectionChannel::Messenger,
            'instagram' => ConnectionChannel::Instagram,
            default => null,
        };
    }

    /**
     * Account ids (page id / Instagram account id) of the entries.
     *
     * @param  array<array-key, mixed>  $payload
     * @return list<string>
     */
    public static function accountIds(array $payload): array
    {
        $ids = [];

        foreach (self::list($payload['entry'] ?? null) as $entry) {
            if (isset($entry['id']) && is_scalar($entry['id']) && (string) $entry['id'] !== '') {
                $ids[] = (string) $entry['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The body restricted to the entries of one account.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function forAccount(array $payload, string $accountId): array
    {
        return [
            'object' => $payload['object'] ?? null,
            'entry' => array_values(array_filter(self::list($payload['entry'] ?? null), fn (array $entry): bool => (string) ($entry['id'] ?? '') === $accountId)),
        ];
    }

    /**
     * Kinds of the items of the body, for the webhook log ("messages,read").
     *
     * @param  array<array-key, mixed>  $payload
     * @return list<string>
     */
    public static function fields(array $payload): array
    {
        $fields = [];

        foreach (self::list($payload['entry'] ?? null) as $entry) {
            foreach (self::list($entry['messaging'] ?? null) as $item) {
                foreach (['message', 'postback', 'delivery', 'read', 'reaction', 'optin', 'referral', 'policy-enforcement'] as $key) {
                    if (isset($item[$key])) {
                        $fields[] = $key;
                    }
                }
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return list<MetaMessagingEvent>
     */
    public static function events(array $payload): array
    {
        $channel = self::channelOf($payload['object'] ?? null);

        if ($channel === null) {
            return [];
        }

        $events = [];

        foreach (self::list($payload['entry'] ?? null) as $entry) {
            $accountId = isset($entry['id']) && is_scalar($entry['id']) ? (string) $entry['id'] : '';

            if ($accountId === '') {
                continue;
            }

            foreach (self::list($entry['messaging'] ?? null) as $item) {
                try {
                    array_push($events, ...self::eventsOf($channel, $accountId, $item));
                } catch (InvalidArgumentException $exception) {
                    Log::warning('Meta messaging item ignored: '.$exception->getMessage(), ['channel' => $channel->value, 'account_id' => $accountId]);
                }
            }
        }

        usort($events, fn (MetaMessagingEvent $a, MetaMessagingEvent $b): int => $a->occurredAt <=> $b->occurredAt);

        return $events;
    }

    /**
     * Meta's message id as stored: ids longer than MAX_MESSAGE_ID_LENGTH
     * (Instagram ids can be long) become "sha256:" + their hash, the same
     * way everywhere (inbound, outbound, echoes and receipts).
     */
    public static function messageId(string $mid): string
    {
        return strlen($mid) <= self::MAX_MESSAGE_ID_LENGTH ? $mid : 'sha256:'.hash('sha256', $mid);
    }

    /**
     * Timestamp of an item (milliseconds), never later than now: a clock
     * ahead on Meta's side must not extend the customer service window.
     */
    public static function moment(mixed $milliseconds): CarbonImmutable
    {
        if (! is_numeric($milliseconds) || (int) $milliseconds <= 0) {
            throw new InvalidArgumentException('The item lacks its timestamp.');
        }

        $at = CarbonImmutable::createFromTimestampMsUTC((int) $milliseconds);
        $now = CarbonImmutable::now('UTC');

        return ($at->greaterThan($now) ? $now : $at)->setTimezone(date_default_timezone_get());
    }

    /**
     * @param  array<array-key, mixed>  $item
     * @return list<MetaMessagingEvent>
     */
    private static function eventsOf(ConnectionChannel $channel, string $accountId, array $item): array
    {
        $policy = $item['policy-enforcement'] ?? $item['policy_enforcement'] ?? null;

        // messaging_policy_enforcement: Meta blocked (or unblocked) the
        // page's messaging; it carries no sender.
        if (is_array($policy)) {
            return [new MetaMessagingEvent(
                MetaMessagingEvent::POLICY,
                $accountId,
                null,
                is_numeric($item['timestamp'] ?? null) ? self::moment($item['timestamp']) : CarbonImmutable::now(),
                ignoredReason: is_string($policy['action'] ?? null) ? Str::limit($policy['action'], 40, '') : 'unknown',
                policyReason: is_string($policy['reason'] ?? null) ? Str::limit($policy['reason'], 500, '') : null,
            )];
        }

        $sender = self::id($item['sender']['id'] ?? null);
        $recipient = self::id($item['recipient']['id'] ?? null);
        $at = self::moment($item['timestamp'] ?? null);

        if (is_array($item['message'] ?? null)) {
            $message = $item['message'];
            $mid = is_string($message['mid'] ?? null) && $message['mid'] !== '' ? self::messageId($message['mid']) : null;

            if (($message['is_echo'] ?? false) === true) {
                return [new MetaMessagingEvent(MetaMessagingEvent::ECHO, $accountId, $recipient, $at, mids: $mid === null ? [] : [$mid])];
            }

            if (($message['is_deleted'] ?? false) === true) {
                return [new MetaMessagingEvent(MetaMessagingEvent::IGNORED, $accountId, $sender, $at, ignoredReason: 'deleted')];
            }

            if ($mid === null || $sender === null) {
                throw new InvalidArgumentException('The message lacks its id or sender.');
            }

            return array_map(
                fn (InboundMessage $inbound): MetaMessagingEvent => new MetaMessagingEvent(MetaMessagingEvent::MESSAGE, $accountId, $sender, $at, $inbound),
                self::messagesOf($channel, $accountId, $sender, $mid, $at, $message, is_array($item['referral'] ?? null) ? $item['referral'] : ($message['referral'] ?? null)),
            );
        }

        if (is_array($item['postback'] ?? null)) {
            $postback = $item['postback'];

            if ($sender === null) {
                throw new InvalidArgumentException('The postback lacks its sender.');
            }

            $title = is_string($postback['title'] ?? null) && trim($postback['title']) !== '' ? $postback['title'] : 'Botón seleccionado';
            $mid = is_string($postback['mid'] ?? null) && $postback['mid'] !== ''
                ? self::messageId($postback['mid'])
                : 'postback:'.hash('sha256', $sender.'|'.($item['timestamp'] ?? '').'|'.json_encode($postback));

            $inbound = new InboundMessage(
                phoneNumberId: $accountId,
                sender: self::sender($channel, $sender, $mid, $at, is_array($postback['referral'] ?? null) ? $postback['referral'] : null),
                type: MessageType::Text,
                body: Str::limit($title, 4096, ''),
                content: ['postback' => array_filter([
                    'title' => $title,
                    'payload' => is_string($postback['payload'] ?? null) ? Str::limit($postback['payload'], 1000, '') : null,
                ])],
            );

            return [new MetaMessagingEvent(MetaMessagingEvent::MESSAGE, $accountId, $sender, $at, $inbound)];
        }

        if (is_array($item['delivery'] ?? null)) {
            $delivery = $item['delivery'];
            $mids = array_values(array_map(fn (string $mid): string => self::messageId($mid), array_filter((array) ($delivery['mids'] ?? []), fn (mixed $mid): bool => is_string($mid) && $mid !== '')));
            $watermark = is_numeric($delivery['watermark'] ?? null) ? self::moment($delivery['watermark']) : null;

            return [new MetaMessagingEvent(MetaMessagingEvent::DELIVERY, $accountId, $sender, $at, mids: $mids, watermark: $watermark)];
        }

        if (is_array($item['read'] ?? null)) {
            $read = $item['read'];
            $mid = is_string($read['mid'] ?? null) && $read['mid'] !== '' ? [self::messageId($read['mid'])] : [];
            $watermark = is_numeric($read['watermark'] ?? null) ? self::moment($read['watermark']) : null;

            return [new MetaMessagingEvent(MetaMessagingEvent::READ, $accountId, $sender, $at, mids: $mid, watermark: $watermark)];
        }

        $reason = match (true) {
            isset($item['reaction']) => 'reaction',
            isset($item['optin']) => 'optin',
            isset($item['referral']) => 'referral',
            default => 'unknown',
        };

        return [new MetaMessagingEvent(MetaMessagingEvent::IGNORED, $accountId, $sender, $at, ignoredReason: $reason)];
    }

    /**
     * @param  array<array-key, mixed>  $message
     * @return list<InboundMessage>
     */
    private static function messagesOf(ConnectionChannel $channel, string $accountId, string $customerId, string $mid, CarbonImmutable $at, array $message, mixed $referral): array
    {
        $text = is_string($message['text'] ?? null) && trim($message['text']) !== '' ? Str::limit($message['text'], 4096, '') : null;
        $context = is_string($message['reply_to']['mid'] ?? null) ? ['id' => self::messageId($message['reply_to']['mid'])] : null;
        $attachments = self::list($message['attachments'] ?? null);
        $quickReply = is_string($message['quick_reply']['payload'] ?? null) ? ['quick_reply' => Str::limit($message['quick_reply']['payload'], 1000, '')] : [];

        if ($attachments === []) {
            $unsupported = ($message['is_unsupported'] ?? false) === true || $text === null;

            return [new InboundMessage(
                phoneNumberId: $accountId,
                sender: self::sender($channel, $customerId, $mid, $at, is_array($referral) ? $referral : null),
                type: $unsupported ? MessageType::Unsupported : MessageType::Text,
                body: $text,
                content: $unsupported ? ['reason' => 'unsupported'] : ['body' => $text, ...$quickReply],
                context: $context,
            )];
        }

        $messages = [];

        foreach ($attachments as $index => $attachment) {
            [$type, $content] = self::attachmentOf($attachment);

            $messages[] = new InboundMessage(
                phoneNumberId: $accountId,
                sender: self::sender($channel, $customerId, $index === 0 ? $mid : self::messageId($mid.'#'.($index + 1)), $at, $index === 0 && is_array($referral) ? $referral : null),
                type: $type,
                body: $index === 0 ? $text : null,
                content: $content,
                context: $index === 0 ? $context : null,
            );
        }

        return $messages;
    }

    /**
     * Media attachments are downloaded later from their URL (Meta's CDN);
     * other kinds (shares, story mentions, templates, locations) are kept
     * as unsupported content.
     *
     * @param  array<array-key, mixed>  $attachment
     * @return array{MessageType, array<string, mixed>}
     */
    private static function attachmentOf(array $attachment): array
    {
        $kind = is_string($attachment['type'] ?? null) ? $attachment['type'] : 'unknown';
        $url = $attachment['payload']['url'] ?? null;
        $type = match ($kind) {
            // Messenger stickers (sticker_id, including the "Like" thumb) are
            // PNG/GIF images, not WhatsApp's WebP stickers.
            'image', 'sticker' => MessageType::Image,
            'audio' => MessageType::Audio,
            'video' => MessageType::Video,
            'file' => MessageType::Document,
            default => MessageType::Unsupported,
        };

        if ($type === MessageType::Unsupported || ! is_string($url) || $url === '') {
            return [MessageType::Unsupported, ['attachment_type' => Str::limit($kind, 40, '')]];
        }

        return [$type, [
            'url' => Str::limit($url, 4000, ''),
            'attachment_type' => $kind,
            'source' => 'url',
        ]];
    }

    /**
     * @param  array<array-key, mixed>|null  $referral
     */
    private static function sender(ConnectionChannel $channel, string $customerId, string $mid, CarbonImmutable $at, ?array $referral): InboundSender
    {
        return new InboundSender(
            channel: $channel,
            provider: $channel->defaultProvider(),
            externalId: $customerId,
            profileName: null,
            messageId: $mid,
            occurredAt: $at,
            referral: $referral === null ? null : ProviderReferral::fromArray(array_filter([
                'source_type' => is_string($referral['source'] ?? null) ? $referral['source'] : null,
                'source_id' => is_scalar($referral['ad_id'] ?? null) ? (string) $referral['ad_id'] : (is_string($referral['ref'] ?? null) ? $referral['ref'] : null),
                'source_url' => is_string($referral['ads_context_data']['photo_url'] ?? null) ? $referral['ads_context_data']['photo_url'] : null,
                'headline' => is_string($referral['ads_context_data']['ad_title'] ?? null) ? $referral['ads_context_data']['ad_title'] : null,
            ])),
        );
    }

    private static function id(mixed $value): ?string
    {
        return is_scalar($value) && preg_match('/^[A-Za-z0-9._:-]{1,64}$/', (string) $value) === 1 ? (string) $value : null;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }
}
