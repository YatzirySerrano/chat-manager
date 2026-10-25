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
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Reads a WhatsApp Cloud API webhook body (object "whatsapp_business_account"):
 * customer messages and their senders, status updates of outbound messages
 * ("statuses") and the other change fields (template status, phone quality,
 * account updates), which are returned raw for their handlers.
 *
 * It only interprets an already authenticated payload: verifying Meta's
 * signature belongs to the webhook endpoint. Items that cannot be
 * interpreted are skipped and logged without personal data; message types
 * this application does not render are kept as "unsupported" with their
 * provider content, never dropped.
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
        $messages = [];

        foreach (self::changes($payload, 'messages') as ['value' => $value]) {
            foreach (self::messagesOf($value) as $message) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * Status updates of outbound messages.
     *
     * @param  array<array-key, mixed>  $payload
     * @return list<ProviderStatus>
     */
    public static function statuses(array $payload): array
    {
        $statuses = [];

        foreach (self::changes($payload, 'messages') as ['value' => $value]) {
            $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;

            if (! is_string($phoneNumberId) || $phoneNumberId === '') {
                continue;
            }

            foreach (self::list($value['statuses'] ?? null) as $status) {
                $id = $status['id'] ?? null;
                $state = $status['status'] ?? null;
                $timestamp = $status['timestamp'] ?? null;

                if (! is_string($id) || $id === '' || ! is_string($state) || ! is_numeric($timestamp)) {
                    Log::warning('Meta webhook status ignored: it lacks its message id, status or timestamp.', ['phone_number_id' => $phoneNumberId]);

                    continue;
                }

                $statuses[] = new ProviderStatus(
                    phoneNumberId: $phoneNumberId,
                    messageId: Str::limit($id, 191, ''),
                    status: strtolower($state),
                    occurredAt: CarbonImmutable::createFromTimestampUTC((int) $timestamp),
                    recipientId: is_string($status['recipient_id'] ?? null) ? $status['recipient_id'] : null,
                    errors: self::errors($status['errors'] ?? null),
                    conversation: is_array($status['conversation'] ?? null) ? $status['conversation'] : null,
                    pricing: is_array($status['pricing'] ?? null) ? $status['pricing'] : null,
                    callbackData: is_string($status['biz_opaque_callback_data'] ?? null) ? Str::limit($status['biz_opaque_callback_data'], 512, '') : null,
                );
            }
        }

        return $statuses;
    }

    /**
     * Changes of the body, optionally of one field only, with the business
     * account id (entry id) they belong to and the entry time Meta reports
     * (Unix seconds, null when absent).
     *
     * @param  array<array-key, mixed>  $payload
     * @return list<array{field: string, entry_id: string|null, entry_time: int|null, value: array<array-key, mixed>}>
     */
    public static function changes(array $payload, ?string $field = null): array
    {
        if (($payload['object'] ?? null) !== 'whatsapp_business_account') {
            Log::warning('Meta webhook ignored: unexpected object.', ['object' => is_string($payload['object'] ?? null) ? Str::limit($payload['object'], 60) : null]);

            return [];
        }

        $changes = [];

        foreach (self::list($payload['entry'] ?? null) as $entry) {
            $entryId = isset($entry['id']) && is_scalar($entry['id']) ? (string) $entry['id'] : null;
            $entryTime = isset($entry['time']) && is_numeric($entry['time']) && (int) $entry['time'] > 0 ? (int) $entry['time'] : null;

            foreach (self::list($entry['changes'] ?? null) as $change) {
                $name = $change['field'] ?? null;

                if (! is_string($name) || ! is_array($change['value'] ?? null) || ($field !== null && $name !== $field)) {
                    continue;
                }

                $changes[] = ['field' => $name, 'entry_id' => $entryId, 'entry_time' => $entryTime, 'value' => $change['value']];
            }
        }

        return $changes;
    }

    /**
     * Errors reported at the level of a "messages" change (no message or
     * status attached), e.g. a webhook delivery problem.
     *
     * @param  array<array-key, mixed>  $payload
     * @return list<array{code: int|null, title: string|null, message: string|null, details: string|null, entry_time: int|null}>
     */
    public static function changeErrors(array $payload): array
    {
        $errors = [];

        foreach (self::changes($payload, 'messages') as ['value' => $value, 'entry_time' => $entryTime]) {
            foreach (self::errors($value['errors'] ?? null) as $error) {
                $errors[] = [...$error, 'entry_time' => $entryTime];
            }
        }

        return $errors;
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
                $content = self::contentOf($type, $message);

                $messages[] = new InboundMessage(
                    phoneNumberId: $phoneNumberId,
                    sender: new InboundSender(
                        channel: ConnectionChannel::Whatsapp,
                        provider: ConnectionProvider::MetaCloudApi,
                        externalId: $from,
                        profileName: $profileNames[$from] ?? null,
                        messageId: $messageId,
                        occurredAt: self::notAfterNow(CarbonImmutable::createFromTimestampUTC((int) $timestamp)),
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

    /**
     * Provider content of the message type. "contacts" is a list; types the
     * Cloud API cannot describe ("unsupported") carry their errors instead.
     *
     * @param  array<array-key, mixed>  $message
     * @return array<array-key, mixed>|null
     */
    private static function contentOf(string $type, array $message): ?array
    {
        if ($type === 'contacts') {
            return is_array($message['contacts'] ?? null) ? ['contacts' => array_values($message['contacts'])] : null;
        }

        if (is_array($message[$type] ?? null)) {
            return $message[$type];
        }

        if (is_array($message['errors'] ?? null)) {
            return ['errors' => self::errors($message['errors'])];
        }

        return null;
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
     * the option chosen in an interactive message, the reaction emoji, the
     * place shared or the people shared.
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
            'location' => self::locationText($content),
            'contacts' => self::contactsText($content),
            default => null,
        };

        return is_string($text) && trim($text) !== '' ? Str::limit($text, 4096, '') : null;
    }

    /**
     * @param  array<array-key, mixed>|null  $content
     */
    private static function locationText(?array $content): ?string
    {
        $label = $content['name'] ?? $content['address'] ?? null;

        if (is_string($label) && trim($label) !== '') {
            return $label;
        }

        $latitude = $content['latitude'] ?? null;
        $longitude = $content['longitude'] ?? null;

        return is_numeric($latitude) && is_numeric($longitude) ? "Ubicación: {$latitude}, {$longitude}" : null;
    }

    /**
     * @param  array<array-key, mixed>|null  $content
     */
    private static function contactsText(?array $content): ?string
    {
        $names = [];

        foreach (self::list($content['contacts'] ?? null) as $contact) {
            $name = $contact['name']['formatted_name'] ?? $contact['name']['first_name'] ?? null;
            $phone = self::list($contact['phones'] ?? null)[0]['phone'] ?? null;

            if (is_string($name) && $name !== '') {
                $names[] = is_string($phone) && $phone !== '' ? "{$name} ({$phone})" : $name;
            }
        }

        return $names === [] ? null : 'Contacto compartido: '.implode(', ', $names);
    }

    /**
     * A message time later than now (clock skew on Meta's side) is capped at
     * now, so it can never extend the 24 hour customer service window.
     */
    private static function notAfterNow(CarbonImmutable $at): CarbonImmutable
    {
        $now = CarbonImmutable::now('UTC');

        return $at->greaterThan($now) ? $now : $at;
    }

    /**
     * @return list<array{code: int|null, title: string|null, message: string|null, details: string|null}>
     */
    private static function errors(mixed $errors): array
    {
        return array_map(fn (array $error): array => [
            'code' => is_numeric($error['code'] ?? null) ? (int) $error['code'] : null,
            'title' => is_string($error['title'] ?? null) ? Str::limit($error['title'], 200) : null,
            'message' => is_string($error['message'] ?? null) ? Str::limit($error['message'], 300) : null,
            'details' => is_string($error['error_data']['details'] ?? null) ? Str::limit($error['error_data']['details'], 300) : null,
        ], self::list($errors));
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }
}
