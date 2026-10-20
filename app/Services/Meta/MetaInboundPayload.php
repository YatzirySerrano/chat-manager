<?php

namespace App\Services\Meta;

use App\Enums\ConnectionChannel;
use App\Enums\ConnectionProvider;
use App\Support\InboundSender;
use App\Support\ProviderReferral;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Reads the senders of inbound messages from a WhatsApp Cloud API webhook
 * body (object "whatsapp_business_account", change field "messages").
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
        if (($payload['object'] ?? null) !== 'whatsapp_business_account') {
            Log::warning('Meta webhook ignored: unexpected object.', ['object' => is_string($payload['object'] ?? null) ? $payload['object'] : null]);

            return [];
        }

        $senders = [];

        foreach (self::list($payload['entry'] ?? null) as $entry) {
            foreach (self::list($entry['changes'] ?? null) as $change) {
                if (($change['field'] ?? null) !== 'messages' || ! is_array($change['value'] ?? null)) {
                    continue;
                }

                foreach (self::sendersOf($change['value']) as $sender) {
                    $senders[] = $sender;
                }
            }
        }

        return $senders;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return list<array{phone_number_id: string, sender: InboundSender}>
     */
    private static function sendersOf(array $value): array
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

        $senders = [];

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

                $senders[] = [
                    'phone_number_id' => $phoneNumberId,
                    'sender' => new InboundSender(
                        channel: ConnectionChannel::Whatsapp,
                        provider: ConnectionProvider::MetaCloudApi,
                        externalId: $from,
                        profileName: $profileNames[$from] ?? null,
                        messageId: $messageId,
                        occurredAt: CarbonImmutable::createFromTimestampUTC((int) $timestamp),
                        referral: is_array($message['referral'] ?? null) ? ProviderReferral::fromArray($message['referral']) : null,
                    ),
                ];
            } catch (InvalidArgumentException $exception) {
                Log::warning('Meta webhook message ignored: '.$exception->getMessage(), [
                    'message_id' => $messageId,
                    'phone_number_id' => $phoneNumberId,
                ]);
            }
        }

        return $senders;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }
}
