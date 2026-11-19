<?php

namespace App\Services\Telegram;

use App\Enums\ConnectionChannel;
use App\Enums\ConnectionProvider;
use App\Enums\MessageType;
use App\Models\Connection;
use App\Support\InboundMessage;
use App\Support\InboundSender;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * One Telegram update (https://core.telegram.org/bots/api#update) read for
 * a bot connection, already validated:
 *
 * - message: a customer message in a private chat ($message), in the shape
 *   every channel feeds ReceiveInboundMessage (content like WhatsApp's:
 *   text body, media with caption, location, contacts). A file carries a
 *   descriptor (file_id) that is moved to the attachment and downloaded by
 *   DownloadTelegramMedia; photos use their largest size, animated stickers
 *   their static thumbnail;
 * - edit: the customer edited a message ($editedMessageId, $editedText,
 *   $editedAt), applied by RecordMessageEdit;
 * - ignored: groups, channels, other bots, button presses (this platform
 *   never sends buttons) and anything else, with the reason for the log.
 *
 * The customer is the Telegram user id (digits; global, the same for every
 * bot). Stored message ids are "tg.{connection}.{chat}.{message}": Telegram
 * numbers messages per chat, so the chat and the bot are part of the key.
 */
final readonly class TelegramUpdate
{
    public const MESSAGE = 'message';

    public const EDIT = 'edit';

    public const IGNORED = 'ignored';

    /** Message kinds that carry a file, in the order they are looked for. */
    private const MEDIA_FIELDS = ['photo', 'video', 'video_note', 'animation', 'voice', 'audio', 'document', 'sticker'];

    public function __construct(
        public string $kind,
        public ?InboundMessage $message = null,
        public ?string $editedMessageId = null,
        public ?string $editedText = null,
        public ?CarbonImmutable $editedAt = null,
        public ?string $ignoredReason = null,
        public ?int $updateId = null,
    ) {}

    /**
     * Stored id of a Telegram message of the connection.
     */
    public static function storedId(int $connectionId, string $chatId, int|string $messageId): string
    {
        return "tg.{$connectionId}.{$chatId}.{$messageId}";
    }

    /**
     * Chat and Telegram message id of a stored id of this connection; null
     * when it belongs to another connection or channel.
     *
     * @return array{chat_id: string, message_id: int}|null
     */
    public static function parseStoredId(int $connectionId, ?string $storedId): ?array
    {
        if (! is_string($storedId) || preg_match('/^tg\.'.$connectionId.'\.(-?\d{1,20})\.(\d{1,20})$/', $storedId, $matches) !== 1) {
            return null;
        }

        return ['chat_id' => $matches[1], 'message_id' => (int) $matches[2]];
    }

    /**
     * @param  array<array-key, mixed>  $update
     */
    public static function parse(Connection $connection, array $update): self
    {
        $updateId = is_int($update['update_id'] ?? null) ? $update['update_id'] : null;

        if (is_array($update['callback_query'] ?? null)) {
            return self::ignored('callback_query', $updateId);
        }

        $edited = is_array($update['edited_message'] ?? null);
        $message = $edited ? $update['edited_message'] : ($update['message'] ?? null);

        if (! is_array($message)) {
            return self::ignored('unsupported_update', $updateId);
        }

        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $from = is_array($message['from'] ?? null) ? $message['from'] : [];

        if (($chat['type'] ?? null) !== 'private') {
            return self::ignored('not_private_chat', $updateId);
        }

        if (($from['is_bot'] ?? false) === true) {
            return self::ignored('from_bot', $updateId);
        }

        if (! is_numeric($from['id'] ?? null) || ! is_numeric($chat['id'] ?? null) || ! is_int($message['message_id'] ?? null)) {
            return self::ignored('malformed', $updateId);
        }

        $chatId = (string) $chat['id'];
        $storedId = self::storedId($connection->id, $chatId, $message['message_id']);

        if ($edited) {
            $text = self::text($message['text'] ?? null) ?? self::text($message['caption'] ?? null);
            $at = is_int($message['edit_date'] ?? null) ? CarbonImmutable::createFromTimestamp($message['edit_date']) : CarbonImmutable::now();

            return new self(self::EDIT, editedMessageId: $storedId, editedText: $text, editedAt: $at, updateId: $updateId);
        }

        [$type, $body, $content] = self::content($message);

        try {
            $sender = new InboundSender(
                ConnectionChannel::Telegram,
                ConnectionProvider::TelegramBot,
                (string) $from['id'],
                self::profileName($from),
                $storedId,
                is_int($message['date'] ?? null) ? CarbonImmutable::createFromTimestamp($message['date']) : CarbonImmutable::now(),
            );
        } catch (InvalidArgumentException) {
            return self::ignored('malformed', $updateId);
        }

        return new self(self::MESSAGE, message: new InboundMessage(
            phoneNumberId: (string) $connection->external_account_id,
            sender: $sender,
            type: $type,
            body: $body,
            content: $content,
            context: self::context($connection, $chatId, $message),
        ), updateId: $updateId);
    }

    private static function ignored(string $reason, ?int $updateId): self
    {
        return new self(self::IGNORED, ignoredReason: $reason, updateId: $updateId);
    }

    /**
     * "Ana López" from first and last name, else the @username.
     *
     * @param  array<array-key, mixed>  $from
     */
    private static function profileName(array $from): ?string
    {
        $name = trim(implode(' ', array_filter([
            is_string($from['first_name'] ?? null) ? $from['first_name'] : null,
            is_string($from['last_name'] ?? null) ? $from['last_name'] : null,
        ])));

        if ($name !== '') {
            return $name;
        }

        return is_string($from['username'] ?? null) && $from['username'] !== '' ? '@'.$from['username'] : null;
    }

    /**
     * Type, readable text and content of the message.
     *
     * @param  array<array-key, mixed>  $message
     * @return array{MessageType, string|null, array<array-key, mixed>|null}
     */
    private static function content(array $message): array
    {
        $caption = self::text($message['caption'] ?? null);

        if (($text = self::text($message['text'] ?? null)) !== null) {
            return [MessageType::Text, $text, ['body' => $text]];
        }

        foreach (self::MEDIA_FIELDS as $field) {
            if (isset($message[$field]) && is_array($message[$field])) {
                return self::media($field, $message[$field], $caption);
            }
        }

        if (is_array($message['location'] ?? null)) {
            $venue = is_array($message['venue'] ?? null) ? $message['venue'] : [];
            $location = $message['location'];
            $content = array_filter([
                'latitude' => is_numeric($location['latitude'] ?? null) ? (float) $location['latitude'] : null,
                'longitude' => is_numeric($location['longitude'] ?? null) ? (float) $location['longitude'] : null,
                'name' => is_string($venue['title'] ?? null) ? Str::limit($venue['title'], 255, '') : null,
                'address' => is_string($venue['address'] ?? null) ? Str::limit($venue['address'], 500, '') : null,
                'live' => is_int($location['live_period'] ?? null) ? true : null,
            ], fn (mixed $value): bool => $value !== null);
            $text = implode(', ', array_filter([$content['name'] ?? null, $content['address'] ?? null]));
            $text = $text !== '' ? $text : (isset($content['latitude'], $content['longitude']) ? $content['latitude'].', '.$content['longitude'] : null);

            return [MessageType::Location, $text, $content];
        }

        if (is_array($message['contact'] ?? null)) {
            $contact = $message['contact'];
            $name = trim(implode(' ', array_filter([
                is_string($contact['first_name'] ?? null) ? $contact['first_name'] : null,
                is_string($contact['last_name'] ?? null) ? $contact['last_name'] : null,
            ])));
            $phone = is_string($contact['phone_number'] ?? null) ? Str::limit($contact['phone_number'], 40, '') : null;

            return [MessageType::Contacts, $name !== '' ? $name : $phone, ['contacts' => [[
                'name' => ['formatted_name' => Str::limit($name, 255, '')],
                'phones' => $phone === null ? [] : [['phone' => $phone]],
            ]]]];
        }

        $kind = match (true) {
            isset($message['poll']) => 'Encuesta',
            isset($message['dice']) => 'Dado',
            isset($message['game']) => 'Juego',
            isset($message['story']) => 'Historia',
            default => 'Mensaje no compatible',
        };

        return [MessageType::Unsupported, $caption === null ? "[{$kind}]" : "[{$kind}] {$caption}", ['telegram_type' => Str::slug($kind, '_')]];
    }

    /**
     * @param  array<array-key, mixed>  $media
     * @return array{MessageType, string|null, array<array-key, mixed>}
     */
    private static function media(string $field, array $media, ?string $caption): array
    {
        // A photo is a list of sizes: the largest is the last one.
        if ($field === 'photo') {
            $sizes = array_values(array_filter($media, 'is_array'));
            $media = $sizes === [] ? [] : $sizes[count($sizes) - 1];
        }

        $mime = is_string($media['mime_type'] ?? null) ? $media['mime_type'] : null;

        // A video sticker (WebM) plays like an animation.
        if ($field === 'sticker' && ($media['is_video'] ?? false) === true) {
            $field = 'animation';
            $mime = 'video/webm';
        }

        // An animated sticker (TGS, a gzipped Lottie file) cannot be shown:
        // its static thumbnail is kept instead.
        if ($field === 'sticker' && ($media['is_animated'] ?? false) === true) {
            $thumbnail = is_array($media['thumbnail'] ?? null) ? $media['thumbnail'] : [];
            $media = [...$thumbnail, 'emoji' => $media['emoji'] ?? null];
            $mime = 'image/webp';
        }

        [$type, $defaultMime] = match ($field) {
            'photo' => [MessageType::Image, 'image/jpeg'],
            'video', 'video_note', 'animation' => [MessageType::Video, 'video/mp4'],
            'voice' => [MessageType::Audio, 'audio/ogg'],
            'audio' => [MessageType::Audio, 'audio/mpeg'],
            'sticker' => [MessageType::Sticker, 'image/webp'],
            default => [MessageType::Document, 'application/octet-stream'],
        };

        $fileId = is_string($media['file_id'] ?? null) && $media['file_id'] !== '' ? $media['file_id'] : null;

        $content = array_filter([
            'source' => 'telegram',
            'mime_type' => $mime ?? $defaultMime,
            'filename' => is_string($media['file_name'] ?? null) ? $media['file_name'] : null,
            'caption' => $caption,
            'voice' => $field === 'voice' ? true : null,
            'gif' => $field === 'animation' ? true : null,
            'emoji' => $field === 'sticker' && is_string($media['emoji'] ?? null) ? $media['emoji'] : null,
            'seconds' => is_int($media['duration'] ?? null) ? $media['duration'] : null,
            'size' => is_int($media['file_size'] ?? null) ? $media['file_size'] : null,
            'width' => is_int($media['width'] ?? null) && $media['width'] > 0 ? $media['width'] : null,
            'height' => is_int($media['height'] ?? null) && $media['height'] > 0 ? $media['height'] : null,
            // Moved to the attachment by ReceiveInboundMessage, never kept in the message.
            'descriptor' => $fileId === null ? null : array_filter([
                'file_id' => $fileId,
                'file_unique_id' => is_string($media['file_unique_id'] ?? null) ? $media['file_unique_id'] : null,
                'file_size' => is_int($media['file_size'] ?? null) ? $media['file_size'] : null,
            ], fn (mixed $value): bool => $value !== null),
        ], fn (mixed $value): bool => $value !== null);

        return [$type, $caption, $content];
    }

    /**
     * The message being replied to, as the context every channel uses
     * (stored id, who wrote it, a short excerpt for the quote).
     *
     * @param  array<array-key, mixed>  $message
     * @return array<string, mixed>|null
     */
    private static function context(Connection $connection, string $chatId, array $message): ?array
    {
        $replied = is_array($message['reply_to_message'] ?? null) ? $message['reply_to_message'] : null;

        if ($replied === null || ! is_int($replied['message_id'] ?? null)) {
            return null;
        }

        $author = is_array($replied['from'] ?? null) ? $replied['from'] : [];
        $preview = self::text($replied['text'] ?? null) ?? self::text($replied['caption'] ?? null);

        return array_filter([
            'id' => self::storedId($connection->id, $chatId, $replied['message_id']),
            'from_me' => is_numeric($author['id'] ?? null) ? (string) $author['id'] === (string) $connection->external_account_id : null,
            'preview' => $preview === null ? null : Str::limit($preview, 200),
        ], fn (mixed $value): bool => $value !== null);
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : Str::limit($value, 4096, '');
    }
}
