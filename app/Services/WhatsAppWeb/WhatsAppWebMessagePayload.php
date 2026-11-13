<?php

namespace App\Services\WhatsAppWeb;

use App\Enums\MessageType;
use App\Models\Connection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * One InboundMessage of the WhatsApp Web service (contract §5), read into
 * what ChatManager stores: the chat it belongs to, the stored message id,
 * the type, the readable text and the content in the shape the interface
 * already renders for WhatsApp Cloud API. Used alike by the live events
 * (WhatsAppWebEvents) and by the history import (ImportWhatsAppWebHistory),
 * so a message looks the same whichever way it arrived.
 *
 * Nothing of the payload is trusted: every field is checked and cut to the
 * size the database keeps.
 */
final readonly class WhatsAppWebMessagePayload
{
    /** @var list<string> */
    public const MEDIA_TYPES = ['image', 'video', 'audio', 'document', 'sticker'];

    /** Largest embedded preview kept with a message (base64 characters). */
    private const THUMBNAIL_MAX = 44_000;

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function __construct(private Connection $connection, private array $data) {}

    /**
     * The identity of the other person of the 1:1 chat: the digits of a
     * phone JID, or "lid:{id}" when WhatsApp only gave a privacy
     * identifier. Groups, broadcasts, statuses and channels: null.
     */
    public function externalId(): ?string
    {
        return self::externalIdOf($this->data['remote_jid'] ?? null, ($this->data['is_group'] ?? false) === true);
    }

    public static function externalIdOf(mixed $remoteJid, bool $isGroup = false): ?string
    {
        $jid = is_string($remoteJid) ? strtolower(trim($remoteJid)) : '';

        if ($jid === '' || $isGroup) {
            return null;
        }

        [$user, $server] = array_pad(explode('@', $jid, 2), 2, '');
        // "5215512345678:12@s.whatsapp.net" names a device of the same account.
        $user = explode(':', $user, 2)[0];

        if ($user === '' || ! ctype_digit($user) || strlen($user) > 30) {
            return null;
        }

        return match ($server) {
            's.whatsapp.net', 'c.us' => $user,
            'lid' => 'lid:'.$user,
            default => null,
        };
    }

    public function fromMe(): bool
    {
        return ($this->data['from_me'] ?? false) === true;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function storedId(): string
    {
        $id = self::storedIdOf($this->connection, $this->data['provider_message_id'] ?? null);

        if ($id === null) {
            throw new InvalidArgumentException('The message id is missing or malformed.');
        }

        return $id;
    }

    /**
     * The id a message of the service is stored under, or null when the
     * given one is unusable.
     */
    public static function storedIdOf(Connection $connection, mixed $id): ?string
    {
        if (! is_string($id) || $id === '' || strlen($id) > 100 || preg_match('/^[A-Za-z0-9._:-]+$/', $id) !== 1) {
            return null;
        }

        return WhatsAppWebTransport::storedId($connection->id, $id);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function occurredAt(): CarbonImmutable
    {
        if (! is_int($this->data['timestamp'] ?? null) || $this->data['timestamp'] <= 0) {
            throw new InvalidArgumentException('The message has no timestamp.');
        }

        return self::timestamp($this->data['timestamp']);
    }

    /**
     * Never in the future (a phone with a wrong clock must not push the
     * conversation ahead of everything else).
     */
    public static function timestamp(mixed $value): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        $at = is_int($value) && $value > 0 ? CarbonImmutable::createFromTimestampUTC($value) : $now;

        return $at->greaterThan($now) ? $now : $at;
    }

    /** Name of the chat in the address book of the phone (history only). */
    public function chatName(): ?string
    {
        return is_string($this->data['chat_name'] ?? null) && trim($this->data['chat_name']) !== '' ? $this->data['chat_name'] : null;
    }

    public function profileName(): ?string
    {
        foreach (['chat_name', 'push_name'] as $key) {
            if (is_string($this->data[$key] ?? null) && trim($this->data[$key]) !== '') {
                return $this->data[$key];
            }
        }

        return null;
    }

    public function rawType(): string
    {
        return is_string($this->data['type'] ?? null) ? $this->data['type'] : 'unsupported';
    }

    public function type(): MessageType
    {
        return match ($this->rawType()) {
            'text', 'image', 'video', 'audio', 'document', 'sticker', 'location', 'contacts', 'reaction', 'poll', 'interactive' => MessageType::from($this->rawType()),
            default => MessageType::Unsupported,
        };
    }

    /**
     * Readable text of the message, as MetaInboundPayload builds it. What
     * the platform does not draw says what it was ("Llamada perdida").
     */
    public function body(): ?string
    {
        $text = is_string($this->data['text'] ?? null) ? $this->data['text'] : null;
        $reaction = is_array($this->data['reaction'] ?? null) ? $this->data['reaction'] : [];

        $body = match ($this->rawType()) {
            'text', 'image', 'video', 'document', 'poll', 'interactive' => $text,
            'reaction' => is_string($reaction['emoji'] ?? null) ? $reaction['emoji'] : $text,
            'location' => $text ?? $this->locationText(is_array($this->data['location'] ?? null) ? $this->data['location'] : []),
            'contacts' => $this->contactsText(is_array($this->data['contacts'] ?? null) ? $this->data['contacts'] : []),
            'audio', 'sticker' => null,
            default => $this->unsupportedText($text),
        };

        if ($body === null) {
            return null;
        }

        $body = trim($body);

        return $body === '' ? null : Str::limit($body, 4096, '');
    }

    /**
     * Content in the shape the interface already renders for WhatsApp
     * Cloud API (text body, media, location, contacts, reaction). For a
     * file it carries the download descriptor, which is moved to the
     * attachment (encrypted) and never stored in the message.
     *
     * @return array<array-key, mixed>|null
     */
    public function content(): ?array
    {
        $type = $this->rawType();
        $data = $this->data;
        $filled = fn (mixed $value): bool => $value !== null;

        if ($type === 'text') {
            return is_string($data['text'] ?? null) ? ['body' => $data['text']] : null;
        }

        if (in_array($type, self::MEDIA_TYPES, true)) {
            $media = is_array($data['media'] ?? null) ? $data['media'] : [];
            $descriptor = is_array($media['descriptor'] ?? null) && $media['descriptor'] !== [] ? $media['descriptor'] : null;
            $thumbnail = is_string($media['thumbnail'] ?? null) && strlen($media['thumbnail']) <= self::THUMBNAIL_MAX
                && preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $media['thumbnail']) === 1 ? $media['thumbnail'] : null;

            return array_filter([
                'source' => 'whatsapp_web',
                'mime_type' => is_string($media['mime'] ?? null) ? $media['mime'] : null,
                'filename' => is_string($media['file_name'] ?? null) ? $media['file_name'] : null,
                'caption' => is_string($data['text'] ?? null) && $data['text'] !== '' ? $data['text'] : null,
                'voice' => ($media['voice'] ?? false) === true ? true : null,
                'gif' => ($media['gif'] ?? false) === true ? true : null,
                'animated' => ($media['animated'] ?? false) === true ? true : null,
                'view_once' => ($data['view_once'] ?? false) === true ? true : null,
                'seconds' => is_int($media['seconds'] ?? null) && $media['seconds'] >= 0 ? $media['seconds'] : null,
                'size' => is_int($media['size'] ?? null) ? $media['size'] : null,
                'width' => self::dimension($media['width'] ?? null),
                'height' => self::dimension($media['height'] ?? null),
                'thumbnail' => $thumbnail,
                // Without it the file cannot be fetched: the message is kept, the file is not.
                'descriptor' => $descriptor,
            ], $filled);
        }

        if ($type === 'location') {
            $location = is_array($data['location'] ?? null) ? $data['location'] : [];

            return array_filter([
                'latitude' => is_numeric($location['latitude'] ?? null) ? (float) $location['latitude'] : null,
                'longitude' => is_numeric($location['longitude'] ?? null) ? (float) $location['longitude'] : null,
                'name' => is_string($location['name'] ?? null) ? Str::limit($location['name'], 255, '') : null,
                'address' => is_string($location['address'] ?? null) ? Str::limit($location['address'], 500, '') : null,
                'live' => ($location['live'] ?? false) === true ? true : null,
            ], $filled);
        }

        if ($type === 'contacts') {
            $contacts = [];

            foreach (array_slice(array_values(is_array($data['contacts'] ?? null) ? $data['contacts'] : []), 0, 20) as $contact) {
                if (! is_array($contact)) {
                    continue;
                }

                $phones = array_values(array_filter((array) ($contact['phones'] ?? []), 'is_string'));
                $contacts[] = [
                    'name' => ['formatted_name' => is_string($contact['name'] ?? null) ? Str::limit($contact['name'], 255, '') : ''],
                    'phones' => array_map(fn (string $phone): array => ['phone' => Str::limit($phone, 40, '')], array_slice($phones, 0, 10)),
                ];
            }

            return ['contacts' => $contacts];
        }

        if ($type === 'reaction') {
            return array_filter([
                'message_id' => $this->reactionTarget(),
                'emoji' => $this->reactionEmoji(),
            ], $filled);
        }

        if ($type === 'poll') {
            $poll = is_array($data['poll'] ?? null) ? $data['poll'] : [];
            $options = array_values(array_filter((array) ($poll['options'] ?? []), fn (mixed $option): bool => is_string($option) && trim($option) !== ''));

            return [
                'question' => is_string($poll['question'] ?? null) ? Str::limit($poll['question'], 300, '') : null,
                'options' => array_map(fn (string $option): string => Str::limit($option, 150, ''), array_slice($options, 0, 12)),
                'multiple' => ($poll['multiple'] ?? false) === true,
            ];
        }

        if ($type === 'interactive') {
            $interactive = is_array($data['interactive'] ?? null) ? $data['interactive'] : [];
            $kind = ($interactive['kind'] ?? null) === 'list_reply' ? 'list_reply' : 'button_reply';

            // The shape of Meta's interactive answers (ConversationMessageResource).
            return [
                'type' => $kind,
                $kind => array_filter([
                    'title' => is_string($interactive['title'] ?? null) ? Str::limit($interactive['title'], 300, '') : null,
                    'description' => is_string($interactive['description'] ?? null) ? Str::limit($interactive['description'], 300, '') : null,
                ], $filled),
            ];
        }

        $unsupported = is_array($data['unsupported'] ?? null) ? $data['unsupported'] : [];

        return array_filter([
            'kind' => is_string($unsupported['kind'] ?? null) ? Str::limit($unsupported['kind'], 40, '') : null,
            'label' => is_string($unsupported['label'] ?? null) ? Str::limit($unsupported['label'], 80, '') : null,
        ], $filled) ?: null;
    }

    /**
     * The stored id of the message being replied to (Meta's "context").
     *
     * @return array{id: string}|null
     */
    public function context(): ?array
    {
        // A reaction points at its message through "reaction", not as a reply.
        if ($this->rawType() === 'reaction') {
            return null;
        }

        $quoted = is_array($this->data['quoted'] ?? null) ? self::storedIdOf($this->connection, $this->data['quoted']['provider_message_id'] ?? null) : null;

        return $quoted === null ? null : ['id' => $quoted];
    }

    /**
     * What is kept about the quoted message: its stored id and, when
     * WhatsApp sent them, who wrote it and an excerpt, so the quote can be
     * drawn even when the original was never stored here.
     *
     * @return array{message_id: string, from_me?: bool, preview?: string}|null
     */
    public function quote(): ?array
    {
        $context = $this->context();

        if ($context === null) {
            return null;
        }

        $quoted = (array) $this->data['quoted'];
        $quote = ['message_id' => $context['id']];

        if (is_bool($quoted['from_me'] ?? null)) {
            $quote['from_me'] = $quoted['from_me'];
        }

        if (is_string($quoted['text'] ?? null) && trim($quoted['text']) !== '') {
            $quote['preview'] = Str::limit(trim($quoted['text']), 280, '');
        }

        return $quote;
    }

    /** The stored id of the message a reaction points at. */
    public function reactionTarget(): ?string
    {
        $reaction = is_array($this->data['reaction'] ?? null) ? $this->data['reaction'] : [];
        $quoted = is_array($this->data['quoted'] ?? null) ? $this->data['quoted'] : [];

        return self::storedIdOf($this->connection, $reaction['provider_message_id'] ?? $quoted['provider_message_id'] ?? null);
    }

    /** The emoji of a reaction; empty when the reaction was removed. */
    public function reactionEmoji(): string
    {
        $reaction = is_array($this->data['reaction'] ?? null) ? $this->data['reaction'] : [];
        $emoji = is_string($reaction['emoji'] ?? null) ? $reaction['emoji'] : (is_string($this->data['text'] ?? null) ? $this->data['text'] : '');

        return Str::limit(trim($emoji), 16, '');
    }

    /**
     * Reactions the history carries attached to the message.
     *
     * @return list<array{emoji: string, from_me: bool}>
     */
    public function reactions(): array
    {
        $reactions = [];

        foreach (array_slice(array_values(is_array($this->data['reactions'] ?? null) ? $this->data['reactions'] : []), 0, 4) as $reaction) {
            if (is_array($reaction) && is_string($reaction['emoji'] ?? null) && trim($reaction['emoji']) !== '') {
                $reactions[] = ['emoji' => Str::limit(trim($reaction['emoji']), 16, ''), 'from_me' => ($reaction['from_me'] ?? false) === true];
            }
        }

        return $reactions;
    }

    public function revoked(): bool
    {
        return ($this->data['revoked'] ?? false) === true;
    }

    /** Delivery state the history recorded for a message of the business. */
    public function deliveryStatus(): ?string
    {
        $status = $this->data['status'] ?? null;

        return is_string($status) && in_array($status, ['sent', 'delivered', 'read', 'failed'], true) ? $status : null;
    }

    private static function dimension(mixed $value): ?int
    {
        return is_int($value) && $value > 0 && $value <= 20000 ? $value : null;
    }

    private function unsupportedText(?string $text): ?string
    {
        $unsupported = is_array($this->data['unsupported'] ?? null) ? $this->data['unsupported'] : [];
        $label = is_string($unsupported['label'] ?? null) && trim($unsupported['label']) !== '' ? trim($unsupported['label']) : null;

        if ($label === null) {
            return $text;
        }

        return $text === null || trim($text) === '' ? $label : $label.': '.trim($text);
    }

    /**
     * @param  array<array-key, mixed>  $location
     */
    private function locationText(array $location): ?string
    {
        $parts = array_filter([
            is_string($location['name'] ?? null) ? trim($location['name']) : null,
            is_string($location['address'] ?? null) ? trim($location['address']) : null,
        ], fn (?string $part): bool => $part !== null && $part !== '');

        if ($parts !== []) {
            return implode(', ', $parts);
        }

        return is_numeric($location['latitude'] ?? null) && is_numeric($location['longitude'] ?? null)
            ? $location['latitude'].', '.$location['longitude']
            : null;
    }

    /**
     * @param  array<array-key, mixed>  $contacts
     */
    private function contactsText(array $contacts): ?string
    {
        $names = [];

        foreach ($contacts as $contact) {
            if (is_array($contact) && is_string($contact['name'] ?? null) && trim($contact['name']) !== '') {
                $names[] = trim($contact['name']);
            }
        }

        return $names === [] ? null : implode(', ', array_slice($names, 0, 10));
    }
}
