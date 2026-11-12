<?php

namespace App\Services\WhatsAppWeb;

use App\Enums\AttachmentStatus;
use App\Enums\ConnectionProvider;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\WhatsAppWebState;
use App\Models\Connection;
use App\Models\ContactIdentity;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Messaging\MessageTransport;
use App\Services\Meta\MetaGraphException;
use App\Services\Meta\SendResult;
use App\Services\Meta\WhatsAppTransportUnavailable;
use App\Support\Attachments\AttachmentDownloadLink;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Outbound transport of a WhatsApp connection linked by QR (WhatsApp Web):
 * POST /v1/sessions/{connection}/messages on the private Node service
 * (docs/whatsapp-web-qr-contrato.md §4). It only serves connections whose
 * provider is "whatsapp_web"; WhatsApp Cloud API keeps its own transport.
 *
 * - The idempotency key is stable per message ("cm-msg-{id}"): repeating a
 *   send (an automatic or manual retry) never delivers it twice, because
 *   the service answers the stored result of that key.
 * - A file is never pushed: the service downloads it from a short-lived
 *   internal URL signed for it (WhatsAppWebMediaController).
 * - Failures are reported with the exceptions DeliverOutboundMessage
 *   already understands: a session that is not connected or a missing
 *   configuration fail the message with a clear reason; a service that did
 *   not accept the connection is retried (nothing left this server); a
 *   refused recipient, type or file is a definitive failure; an answer
 *   that never arrived leaves the message "unconfirmed", never re-sent
 *   automatically.
 *
 * Message ids are stored with the prefix "wweb.{connection}." so the same
 * WhatsApp id seen by two linked numbers never collides.
 */
class WhatsAppWebTransport implements MessageTransport
{
    public function __construct(private WhatsAppWebClient $client, private InternalSignature $signature) {}

    public function name(): string
    {
        return 'whatsapp_web';
    }

    /**
     * Stored id of a WhatsApp Web message of the connection.
     */
    public static function storedId(int $connectionId, string $providerMessageId): string
    {
        return "wweb.{$connectionId}.{$providerMessageId}";
    }

    /**
     * WhatsApp's own id of a stored message of the connection; null when
     * the stored id belongs to another connection or transport.
     */
    public static function providerId(int $connectionId, ?string $storedId): ?string
    {
        $prefix = "wweb.{$connectionId}.";

        return is_string($storedId) && str_starts_with($storedId, $prefix) && strlen($storedId) > strlen($prefix)
            ? substr($storedId, strlen($prefix))
            : null;
    }

    public function send(Message $message): SendResult
    {
        $message->loadMissing(['conversation.channelConnection', 'conversation.contact', 'replyTo', 'attachments']);
        $connection = $message->conversation->channelConnection;

        if ($connection === null || ! $connection->isWhatsAppWeb()) {
            throw new WhatsAppTransportUnavailable('La conversación no pertenece a una conexión de WhatsApp Web.');
        }

        if ($connection->webSessionState() !== WhatsAppWebState::Connected) {
            throw new WhatsAppTransportUnavailable($connection->sendingBlockReason() ?? 'La sesión de WhatsApp Web no está conectada.');
        }

        $recipient = $this->recipient($message);

        $payload = [
            'idempotency_key' => 'cm-msg-'.$message->id,
            'to' => $recipient,
            ...$this->content($message),
        ];

        $quoted = $this->quoted($message, $connection, $recipient);

        if ($quoted !== null) {
            $payload['quoted'] = $quoted;
        }

        try {
            $answer = $this->client->sendMessage($connection->id, $payload);
        } catch (WhatsAppWebException $exception) {
            throw $this->translate($exception, $connection);
        }

        return new SendResult(self::storedId($connection->id, $answer['provider_message_id']));
    }

    /**
     * Who receives the message: the JID of the chat when WhatsApp Web
     * already saw this person ("…@s.whatsapp.net", or "…@lid" when WhatsApp
     * never gave a phone); otherwise the digits of the identity known from
     * WhatsApp Cloud API or of the contact's phone, for which the service
     * asks WhatsApp the registered JID.
     */
    private function recipient(Message $message): string
    {
        $known = ContactIdentity::query()
            ->where('contact_id', $message->conversation->contact_id)
            ->where('channel', 'whatsapp')
            ->orderByRaw('provider = ? desc', [ConnectionProvider::WhatsappWeb->value])
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->first(['external_id', 'provider']);
        $identity = $known?->external_id;

        if (is_string($identity) && str_starts_with($identity, 'lid:') && ctype_digit(substr($identity, 4))) {
            return substr($identity, 4).'@lid';
        }

        // Seen through WhatsApp Web: this is the chat's own JID, no lookup needed.
        if (is_string($identity) && ctype_digit($identity) && $known->provider === ConnectionProvider::WhatsappWeb) {
            return $identity.'@s.whatsapp.net';
        }

        $recipient = is_string($identity) && ctype_digit($identity)
            ? $identity
            : preg_replace('/\D+/', '', (string) $message->conversation->contact?->phone_e164);

        if (! is_string($recipient) || $recipient === '') {
            throw new MetaGraphException('El contacto no tiene un número de WhatsApp al cual enviar.', rejected: true, kind: MetaGraphException::INVALID_REQUEST);
        }

        return $recipient;
    }

    /**
     * @return array<string, mixed>
     */
    private function content(Message $message): array
    {
        return match ($message->type) {
            MessageType::Text => ['type' => 'text', 'text' => (string) $message->body],
            MessageType::Image, MessageType::Video, MessageType::Audio, MessageType::Document, MessageType::Sticker => $this->media($message),
            MessageType::Template => throw new MetaGraphException('Las plantillas solo existen en WhatsApp Cloud API; esta conexión usa WhatsApp Web (QR).', rejected: true, kind: MetaGraphException::INVALID_REQUEST),
            default => throw new MetaGraphException('Este tipo de mensaje no se puede enviar por WhatsApp Web.', rejected: true, kind: MetaGraphException::INVALID_REQUEST),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function media(Message $message): array
    {
        $attachment = $message->attachments->first();

        if (! $attachment instanceof MessageAttachment || $attachment->status !== AttachmentStatus::Available) {
            throw new MetaGraphException('El archivo adjunto no está disponible para enviarlo.', rejected: true, kind: MetaGraphException::INVALID_REQUEST);
        }

        // A file WhatsApp has no type for (ZIP) goes as a text with a signed
        // expiring download link, exactly as in WhatsApp Cloud API.
        if (AttachmentDownloadLink::attachmentOf($message) !== null) {
            if (AttachmentDownloadLink::isRevoked($message)) {
                throw new MetaGraphException('El enlace de descarga de este archivo fue revocado; no se volverá a enviar.', rejected: true, kind: MetaGraphException::INVALID_REQUEST);
            }

            if (AttachmentDownloadLink::isExpired($message)) {
                throw new MetaGraphException('El enlace de descarga del archivo ya venció; vuelve a adjuntarlo en un mensaje nuevo.', rejected: true, kind: MetaGraphException::INVALID_REQUEST);
            }

            return ['type' => 'text', 'text' => app(AttachmentDownloadLink::class)->text($message, $attachment)];
        }

        if ($attachment->path === null || $attachment->path === '') {
            throw new MetaGraphException('El archivo adjunto ya no existe en el almacenamiento.', rejected: true, kind: MetaGraphException::INVALID_REQUEST);
        }

        $type = $message->type->value;
        $caption = trim((string) $message->body);

        return [
            'type' => $type,
            'text' => $caption !== '' && in_array($type, ['image', 'video', 'document'], true) ? $caption : null,
            'media' => [
                'url' => $this->mediaUrl($attachment),
                'mime' => $attachment->mime_type,
                'file_name' => $attachment->file_name ?? basename($attachment->path),
                // An OGG/Opus audio is sent as a voice note, like the one recorded in the composer.
                'voice' => $type === 'audio' && str_starts_with($attachment->mime_type, 'audio/ogg'),
            ],
        ];
    }

    /**
     * Short-lived internal URL of the file, signed for the service in the
     * query (ts, nonce, sig): contract §6.
     */
    public function mediaUrl(MessageAttachment $attachment): string
    {
        $path = route('internal.whatsapp-web.media', ['attachment' => $attachment->id], false);
        $query = $this->signature->query('GET', $path);

        if ($query === null) {
            throw new WhatsAppTransportUnavailable('WhatsApp Web (QR) no está configurado en este servidor: falta el secreto interno (WHATSAPP_WEB_SECRET).');
        }

        $base = rtrim((string) (config('whatsapp_web.callback_url') ?: URL::to('/')), '/');

        return $base.$path.'?'.http_build_query($query);
    }

    /**
     * The message being replied to, when it is a WhatsApp Web message of
     * this same connection (anything else cannot be quoted by WhatsApp).
     *
     * @return array{provider_message_id: string, from_me: bool, remote_jid: string, text?: string}|null
     */
    private function quoted(Message $message, Connection $connection, string $recipient): ?array
    {
        $replied = $message->replyTo;
        $id = self::providerId($connection->id, $replied?->whatsapp_message_id);

        if ($replied === null || $id === null) {
            return null;
        }

        $text = trim((string) $replied->body);

        return [
            'provider_message_id' => $id,
            'from_me' => $replied->direction === MessageDirection::Outbound,
            'remote_jid' => str_contains($recipient, '@') ? $recipient : $recipient.'@s.whatsapp.net',
            // The service keeps no messages: without the text the quote would be shown empty.
            ...($text === '' ? [] : ['text' => Str::limit($text, 1024, '')]),
        ];
    }

    /**
     * The failure in the terms the delivery job acts on.
     */
    private function translate(WhatsAppWebException $exception, Connection $connection): \RuntimeException
    {
        return match ($exception->kind) {
            // Nothing left this server: safe to retry with the delivery backoff.
            WhatsAppWebException::UNREACHABLE => new MetaGraphException($exception->getMessage(), rejected: false, kind: MetaGraphException::UNREACHABLE),
            // The request left and nobody answered: never re-sent automatically.
            WhatsAppWebException::AMBIGUOUS => new MetaGraphException($exception->getMessage().' No se reenviará de forma automática para no duplicarlo.', rejected: false, kind: MetaGraphException::AMBIGUOUS),
            WhatsAppWebException::REJECTED => new MetaGraphException($exception->getMessage(), rejected: true, status: $exception->status, kind: MetaGraphException::INVALID_REQUEST),
            WhatsAppWebException::NOT_CONNECTED, WhatsAppWebException::UNKNOWN_SESSION, WhatsAppWebException::NOT_LINKED => $this->sessionUnavailable($exception, $connection),
            default => new WhatsAppTransportUnavailable($exception->getMessage()),
        };
    }

    /**
     * The service says the session cannot send: the stored state was stale,
     * so it is refreshed (the composer then explains why) before failing.
     */
    private function sessionUnavailable(WhatsAppWebException $exception, Connection $connection): WhatsAppTransportUnavailable
    {
        app(WhatsAppWebSessions::class)->sync($connection);

        return new WhatsAppTransportUnavailable($connection->sendingBlockReason() ?? $exception->getMessage());
    }
}
