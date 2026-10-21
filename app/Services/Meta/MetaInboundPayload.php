<?php

namespace App\Services\Meta;

use App\Enums\ConnectionChannel;
use App\Enums\ConnectionProvider;
use App\Enums\MessageType;
use App\Support\InboundMessage;
use App\Support\InboundSender;
use App\Support\ProviderReferral;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Reads the inbound messages, and their senders, from a WhatsApp Cloud API
 * webhook body (object "whatsapp_business_account", change field "messages").
 *
 * It only interprets an already authenticated payload: verifying Meta's
 * signature belongs to the webhook endpoint. Status updates of outbound
 * messages carry no sender and are ignored, as are "system" notices. Items
 * that cannot be interpreted are skipped and logged without personal data.
 */
class MetaInboundPayload
{
    /**
     * @param  array<array-key, mixed>  $payload
     * @return list<array{phone_number_id: string, sender: InboundSender}>
     */
    public static function senders(array $payload): array
    {
        return array_map(fn (InboundMessage $message): array => [
            'phone_number_id' => $message->phoneNumberId,
            'sender' => $message->sender,
        ], self::messages($payload));
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return list<InboundMessage>
     */
    public static function messages(array $payload): array
    {
        if (($payload['object'] ?? null) !== 'whatsapp_business_account') {
            Log::warning('Meta webhook ignored: unexpected object.', ['object' => is_string($payload['object'] ?? null) ? $payload['object'] : null]);

            return [];
        }

        $messages = [];

        foreach (self::list($payload['entry'] ?? null) as $entry) {
            foreach (self::list($entry['changes'] ?? null) as $change) {
                if (($change['field'] ?? null) !== 'messages' || ! is_array($change['value'] ?? null)) {
                    continue;
                }

                foreach (self::messagesOf($change['value']) as $message) {
                    $messages[] = $message;
                }
            }
        }

        return $messages;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return list<InboundMessage>
     */
    private static function messagesOf(array $value): array
    {
        $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;

        if (! is_string($phoneNumberId) || $phoneNumberId === '') {
            if (isset($value['messages'])) {
                Log::warning('Meta webhook messages ignored: the phone number id is missing.');
            }

            return [];
        }

        $profileNames = [];

        foreach (self::list($value['contacts'] ?? null) as $contact) {
            if (is_string($contact['wa_id'] ?? null) && is_string($contact['profile']['name'] ?? null)) {
                $profileNames[$contact['wa_id']] = $contact['profile']['name'];
            }
        }

        $messages = [];

        foreach (self::list($value['messages'] ?? null) as $message) {
            $messageId = is_string($message['id'] ?? null) ? $message['id'] : null;

            if (($message['type'] ?? null) === 'system') {
                Log::info('Meta webhook system notice ignored.', ['message_id' => $messageId, 'phone_number_id' => $phoneNumberId]);

                continue;
            }

            try {
                $from = $message['from'] ?? null;
                $timestamp = $message['timestamp'] ?? null;

                if (! is_string($from) || $messageId === null || ! is_numeric($timestamp)) {
                    throw new InvalidArgumentException('The message lacks its sender, id or timestamp.');
                }

                $type = is_string($message['type'] ?? null) ? $message['type'] : 'unsupported';
                $content = is_array($message[$type] ?? null) ? $message[$type] : null;

                $messages[] = new InboundMessage(
                    phoneNumberId: $phoneNumberId,
                    sender: new InboundSender(
                        channel: ConnectionChannel::Whatsapp,
                        provider: ConnectionProvider::MetaCloudApi,
                        externalId: $from,
                        profileName: $profileNames[$from] ?? null,
                        messageId: $messageId,
                        occurredAt: CarbonImmutable::createFromTimestampUTC((int) $timestamp),
                        referral: is_array($message['referral'] ?? null) ? ProviderReferral::fromArray($message['referral']) : null,
                    ),
                    type: self::typeOf($type),
                    body: self::bodyOf($type, $content),
                    content: $content,
                    context: is_array($message['context'] ?? null) ? $message['context'] : null,
                );
            } catch (InvalidArgumentException $exception) {
                Log::warning('Meta webhook message ignored: '.$exception->getMessage(), [
                    'message_id' => $messageId,
                    'phone_number_id' => $phoneNumberId,
                ]);
            }
        }

        return $messages;
    }

    private static function typeOf(string $type): MessageType
    {
        return match ($type) {
            'button' => MessageType::Interactive,
            'template' => MessageType::Unsupported,
            default => MessageType::tryFrom($type) ?? MessageType::Unsupported,
        };
    }

    /**
     * Readable text of the message: its text, the caption of a media file,
     * the option chosen in an interactive message or the reaction emoji.
     *
     * @param  array<array-key, mixed>|null  $content
     */
    private static function bodyOf(string $type, ?array $content): ?string
    {
        $text = match ($type) {
            'text' => $content['body'] ?? null,
            'image', 'video', 'document' => $content['caption'] ?? null,
            'interactive' => $content['button_reply']['title'] ?? $content['list_reply']['title'] ?? null,
            'button' => $content['text'] ?? null,
            'reaction' => $content['emoji'] ?? null,
            'location' => $content['name'] ?? $content['address'] ?? null,
            default => null,
        };

        return is_string($text) && trim($text) !== '' ? $text : null;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }
}
