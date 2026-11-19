<?php

namespace App\Services\Telegram;

use App\Enums\AttachmentStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Models\Connection;
use App\Models\ContactIdentity;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Messaging\MessageTransport;
use App\Services\Meta\MetaGraphException;
use App\Services\Meta\SendResult;
use App\Services\Meta\WhatsAppTransportUnavailable;
use App\Support\Attachments\AttachmentDownloadLink;
use App\Support\Attachments\ChannelAttachmentPolicy;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Sends one stored outbound message through a Telegram bot (Bot API):
 * sendMessage for text (plain text, no parse_mode, so nothing needs
 * escaping) and sendPhoto / sendVideo / sendAudio / sendVoice /
 * sendDocument / sendSticker with the file uploaded (multipart) from the
 * media disk. Telegram has no customer service window nor templates.
 *
 * - The chat is the customer's Telegram user id that wrote in this
 *   conversation (kept on its inbound messages), else their identity.
 * - A reply quotes the original with reply_parameters when it is a message
 *   of this same chat (allow_sending_without_reply, so a deleted original
 *   never fails the reply).
 * - A caption over Telegram's 1024 characters goes as a text right after
 *   the file; if that second call fails the message keeps the file's
 *   acceptance with a visible warning (metadata.delivery_warning).
 * - Failures are translated to what DeliverOutboundMessage already acts on
 *   (MetaGraphException kinds): unreachable, 429 and 5xx are retried with
 *   its backoff; a response that never arrived is left unconfirmed (never
 *   re-sent); a revoked token marks the credentials invalid; a blocked bot
 *   or an unknown chat fails the message with the reason.
 *
 * Stored ids are "tg.{connection}.{chat}.{message}" (TelegramUpdate::storedId).
 */
class TelegramTransport implements MessageTransport
{
    /** Longest caption Telegram accepts on a file. */
    public const CAPTION_LIMIT = 1024;

    /** Longest text message Telegram accepts. */
    public const TEXT_LIMIT = 4096;

    public function __construct(
        private TelegramBotClient $client,
        private ChannelAttachmentPolicy $attachments,
        private AttachmentDownloadLink $links,
    ) {}

    public function name(): string
    {
        return 'telegram';
    }

    public function send(Message $message): SendResult
    {
        $message->loadMissing(['conversation.channelConnection', 'replyTo', 'attachments']);
        $connection = $message->conversation->channelConnection;

        if ($connection === null || ! $connection->isTelegram() || blank($connection->access_token)) {
            throw new WhatsAppTransportUnavailable('La conexión de Telegram no tiene guardado el token del bot.');
        }

        $token = (string) $connection->access_token;
        $chatId = $this->chatId($message);
        $reply = $this->replyParameters($message, $connection, $chatId);
        $attachment = $message->attachments->first();

        try {
            if ($attachment instanceof MessageAttachment) {
                return $this->sendAttachment($message, $attachment, $connection, $token, $chatId, $reply);
            }

            if ($message->type !== MessageType::Text) {
                throw new MetaGraphException('Este tipo de mensaje no se puede enviar por Telegram.', rejected: true, kind: MetaGraphException::INVALID_REQUEST);
            }

            $text = trim((string) $message->body);

            if ($text === '' || mb_strlen($text) > self::TEXT_LIMIT) {
                throw new MetaGraphException('Telegram acepta textos de 1 a '.self::TEXT_LIMIT.' caracteres.', rejected: true, kind: MetaGraphException::INVALID_REQUEST);
            }

            $sent = $this->client->sendMessage($token, $chatId, $text, $reply);
        } catch (TelegramException $exception) {
            throw self::translate($exception);
        }

        return new SendResult($this->storedId($connection, $chatId, $sent));
    }

    /**
     * @param  array<string, mixed>|null  $reply
     *
     * @throws TelegramException
     */
    private function sendAttachment(Message $message, MessageAttachment $attachment, Connection $connection, string $token, string $chatId, ?array $reply): SendResult
    {
        // A file delivered as a secure download link goes as a text.
        if (AttachmentDownloadLink::attachmentOf($message) !== null) {
            if (AttachmentDownloadLink::isRevoked($message) || AttachmentDownloadLink::isExpired($message)) {
                throw new MetaGraphException('El enlace de descarga de este archivo ya no es válido; vuelve a adjuntarlo en un mensaje nuevo.', rejected: true, kind: MetaGraphException::INVALID_REQUEST);
            }

            return new SendResult($this->storedId($connection, $chatId, $this->client->sendMessage($token, $chatId, $this->links->text($message, $attachment), $reply)));
        }

        if ($attachment->status !== AttachmentStatus::Available || blank($attachment->path)) {
            throw new MetaGraphException('El archivo adjunto no está disponible para enviarlo.', rejected: true, kind: MetaGraphException::INVALID_REQUEST);
        }

        $kind = $this->attachments->forChannel($connection->channel)->kindFor($attachment->mime_type);

        if ($kind === null || ($attachment->size_bytes !== null && $attachment->size_bytes > $kind->maxBytes)) {
            throw new MetaGraphException('Este archivo no cumple los límites de Telegram (tipo o tamaño).', rejected: true, kind: MetaGraphException::INVALID_REQUEST);
        }

        [$method, $field] = $this->method($message, $attachment);
        $caption = trim((string) $message->body);
        $inline = $caption !== '' && mb_strlen($caption) <= self::CAPTION_LIMIT && $method !== 'sendSticker';
        $stream = $this->open($attachment);

        try {
            $sent = $this->client->sendFile($token, $method, $field, $chatId, $stream, $attachment->file_name ?? basename((string) $attachment->path), $inline ? $caption : null, $reply);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $warning = null;

        // The file already reached the customer: a failed text never fails the message.
        if ($caption !== '' && ! $inline) {
            try {
                $this->client->sendMessage($token, $chatId, mb_substr($caption, 0, self::TEXT_LIMIT));
            } catch (TelegramException $exception) {
                $warning = 'El archivo se envió, pero el texto que lo acompañaba no: '.$exception->getMessage();
            }
        }

        return new SendResult($this->storedId($connection, $chatId, $sent), warning: $warning);
    }

    /**
     * Bot API method and file field for the message: an OGG audio is a
     * voice note (as recorded in the composer), a WebP sticker a sticker.
     *
     * @return array{string, string}
     */
    private function method(Message $message, MessageAttachment $attachment): array
    {
        return match ($message->type) {
            MessageType::Image => str_starts_with($attachment->mime_type, 'image/gif') ? ['sendDocument', 'document'] : ['sendPhoto', 'photo'],
            MessageType::Video => ['sendVideo', 'video'],
            MessageType::Audio => str_starts_with($attachment->mime_type, 'audio/ogg') ? ['sendVoice', 'voice'] : ['sendAudio', 'audio'],
            MessageType::Sticker => ['sendSticker', 'sticker'],
            default => ['sendDocument', 'document'],
        };
    }

    /**
     * @return resource
     */
    private function open(MessageAttachment $attachment)
    {
        try {
            $stream = Storage::disk($attachment->disk)->readStream((string) $attachment->path);
        } catch (Throwable) {
            $stream = null;
        }

        if (! is_resource($stream)) {
            throw new MetaGraphException('No fue posible leer el archivo adjunto en el almacenamiento de archivos; se reintentará en unos minutos.', rejected: false, kind: MetaGraphException::TRANSIENT);
        }

        return $stream;
    }

    /**
     * The Telegram user that wrote in this conversation (its private chat
     * with the bot has the same id), else the contact's identity.
     */
    private function chatId(Message $message): string
    {
        $id = Message::query()
            ->where('conversation_id', $message->conversation_id)
            ->where('direction', MessageDirection::Inbound)
            ->whereNotNull('metadata->sender_external_id')
            ->orderByDesc('id')
            ->toBase()
            ->value('metadata->sender_external_id');
        $id = is_string($id) && $id !== '' ? $id : null;

        $id ??= ContactIdentity::query()
            ->where('contact_id', $message->conversation->contact_id)
            ->where('channel', 'telegram')
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->value('external_id');

        if (! is_string($id) || preg_match('/^-?\d{1,20}$/', $id) !== 1) {
            throw new MetaGraphException('El contacto no tiene una cuenta de Telegram a la cual responder.', rejected: true, kind: MetaGraphException::INVALID_REQUEST);
        }

        return $id;
    }

    /**
     * @return array{message_id: int, allow_sending_without_reply: bool}|null
     */
    private function replyParameters(Message $message, Connection $connection, string $chatId): ?array
    {
        $original = TelegramUpdate::parseStoredId($connection->id, $message->replyTo?->whatsapp_message_id);

        if ($original === null || $original['chat_id'] !== $chatId) {
            return null;
        }

        return ['message_id' => $original['message_id'], 'allow_sending_without_reply' => true];
    }

    /**
     * @param  array<array-key, mixed>  $sent
     */
    private function storedId(Connection $connection, string $chatId, array $sent): string
    {
        if (! is_int($sent['message_id'] ?? null)) {
            throw new MetaGraphException('Telegram aceptó el mensaje pero no devolvió su identificador.', rejected: false, kind: MetaGraphException::AMBIGUOUS);
        }

        return TelegramUpdate::storedId($connection->id, $chatId, $sent['message_id']);
    }

    /**
     * The failure in the terms the delivery job acts on.
     */
    public static function translate(TelegramException $exception): MetaGraphException
    {
        $message = $exception->getMessage();

        return match ($exception->kind) {
            TelegramException::UNREACHABLE => new MetaGraphException($message, rejected: false, status: $exception->status, kind: MetaGraphException::UNREACHABLE),
            TelegramException::RATE_LIMITED => new MetaGraphException($message.($exception->retryAfter !== null ? " Pidió esperar {$exception->retryAfter} s." : ''), rejected: false, status: $exception->status, kind: MetaGraphException::RATE_LIMITED),
            TelegramException::TRANSIENT => new MetaGraphException($message, rejected: false, status: $exception->status, kind: MetaGraphException::TRANSIENT),
            TelegramException::AMBIGUOUS => new MetaGraphException($message.' No se reenviará de forma automática para no duplicarlo.', rejected: false, status: $exception->status, kind: MetaGraphException::AMBIGUOUS),
            TelegramException::UNAUTHORIZED => new MetaGraphException($message, rejected: true, status: $exception->status, kind: MetaGraphException::TOKEN_INVALID),
            TelegramException::FORBIDDEN, TelegramException::CHAT_NOT_FOUND => new MetaGraphException($message, rejected: true, status: $exception->status, kind: MetaGraphException::MESSAGE_REJECTED),
            default => new MetaGraphException($message, rejected: true, status: $exception->status, kind: MetaGraphException::INVALID_REQUEST),
        };
    }
}
