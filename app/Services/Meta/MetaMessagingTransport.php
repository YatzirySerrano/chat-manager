<?php

namespace App\Services\Meta;

use App\Enums\AttachmentDeliveryMethod;
use App\Enums\AttachmentStatus;
use App\Enums\ConnectionChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Models\ContactIdentity;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Conversations\CustomerServiceWindow;
use App\Services\Messaging\MessageTransport;
use App\Support\Attachments\AttachmentDownloadLink;
use App\Support\Attachments\ChannelAttachmentPolicy;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * Sends one stored outbound message through Facebook Messenger
 * (POST /{page-id}/messages) or Instagram (POST graph.instagram.com/me/messages)
 * with the connection's page / Instagram token and appsecret_proof.
 *
 * - The recipient is the customer's page-scoped id (PSID / IGSID) that
 *   wrote in this conversation (kept on its inbound messages).
 * - messaging_type is RESPONSE: the delivery job already blocked replies
 *   outside the window. With services.meta.human_agent_tag enabled (Meta's
 *   approval of the Human Agent feature, off by default) the window lasts
 *   7 days (CustomerServiceWindow) and replies past the first 24 hours go
 *   as MESSAGE_TAG with tag HUMAN_AGENT.
 * - Files travel by URL: Meta downloads them from a signed URL of this
 *   server valid MEDIA_URL_MINUTES (meta.media route), after checking them
 *   again against the channel's limits (ChannelAttachmentPolicy). Files
 *   delivered as a secure download link (ZIP) are sent as text.
 *   Messenger/Instagram do not caption attachments: a text that came with a
 *   file is checked before the file goes out and sent right after it; if
 *   that second call fails the message keeps the file's acceptance with a
 *   visible warning (metadata.delivery_warning), never a silent "sent".
 *
 * Acceptance returns Meta's mid; delivered/read come later from the webhook.
 */
class MetaMessagingTransport implements MessageTransport
{
    public const MEDIA_URL_MINUTES = 60;

    /** Longest text Meta accepts: Messenger 2000 characters, Instagram 1000 bytes. */
    public const TEXT_LIMITS = ['messenger' => 2000, 'instagram' => 1000];

    public function __construct(
        private ConnectionChannel $channel,
        private MetaPlatformClient $client,
        private ChannelAttachmentPolicy $attachments,
        private AttachmentDownloadLink $links,
    ) {}

    public function name(): string
    {
        return 'meta_'.$this->channel->value;
    }

    public function send(Message $message): SendResult
    {
        $message->loadMissing(['conversation.channelConnection', 'attachments']);
        $connection = $message->conversation->channelConnection;

        if ($connection === null || $connection->channel !== $this->channel || blank($connection->external_account_id) || blank($connection->access_token)) {
            throw new WhatsAppTransportUnavailable("La conexión de {$this->channel->label()} no tiene una autorización vigente de Meta. Vuelve a autorizarla desde Conexiones.");
        }

        $token = (string) $connection->access_token;
        $accountId = (string) $connection->external_account_id;
        $envelope = [
            'recipient' => ['id' => $this->recipient($message)],
            ...$this->messagingType($message),
        ];

        $attachment = $message->attachments->first();

        if ($attachment instanceof MessageAttachment && $attachment->delivery_method !== AttachmentDeliveryMethod::Link) {
            $caption = trim((string) $message->body);

            // A text the channel would refuse stops the message before the file goes out.
            if ($caption !== '') {
                $this->assertTextLength($caption);
            }

            $id = $this->client->sendMessage($this->channel, $accountId, $token, [...$envelope, 'message' => ['attachment' => $this->attachment($message, $attachment)]]);

            return new SendResult(MetaMessagingPayload::messageId($id), warning: $this->caption($message, $caption, $accountId, $token, $envelope));
        }

        if ($attachment instanceof MessageAttachment) {
            return new SendResult(MetaMessagingPayload::messageId($this->sendLink($message, $attachment, $accountId, $token, $envelope)));
        }

        $text = (string) $message->body;

        if ($message->type !== MessageType::Text) {
            throw new MetaGraphException("Este tipo de mensaje no se puede enviar por {$this->channel->label()}.", rejected: true, kind: MetaGraphException::INVALID_REQUEST);
        }

        $this->assertTextLength($text);
        $id = $this->client->sendMessage($this->channel, $accountId, $token, [...$envelope, 'message' => ['text' => $text]]);

        return new SendResult(MetaMessagingPayload::messageId($id));
    }

    /**
     * @return array{messaging_type: string, tag?: string}
     */
    private function messagingType(Message $message): array
    {
        if (! CustomerServiceWindow::humanAgentTagEnabled()) {
            return ['messaging_type' => 'RESPONSE'];
        }

        $lastInbound = $message->conversation->last_inbound_at;
        $outsideStandardWindow = $lastInbound === null || $lastInbound->lessThan(now()->subHours(CustomerServiceWindow::WINDOW_HOURS));

        return $outsideStandardWindow ? ['messaging_type' => 'MESSAGE_TAG', 'tag' => 'HUMAN_AGENT'] : ['messaging_type' => 'RESPONSE'];
    }

    /**
     * The PSID / IGSID that wrote in this conversation (page or account
     * scoped, so an id of another page of the same contact never answers
     * here); conversations stored before that id was kept fall back to the
     * contact's most recent identity of the channel.
     */
    private function recipient(Message $message): string
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
            ->where('channel', $this->channel)
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->value('external_id');

        if (! is_string($id) || $id === '') {
            throw new MetaGraphException("El contacto no tiene una identidad de {$this->channel->label()} a la cual responder.", rejected: true, kind: MetaGraphException::INVALID_REQUEST);
        }

        return $id;
    }

    /**
     * @return array{type: string, payload: array{url: string, is_reusable: bool}}
     */
    private function attachment(Message $message, MessageAttachment $attachment): array
    {
        if ($attachment->status !== AttachmentStatus::Available || blank($attachment->path)) {
            throw new MetaGraphException('El archivo adjunto no está disponible para enviarlo.', rejected: true, kind: MetaGraphException::INVALID_REQUEST);
        }

        $kind = $this->attachments->forChannel($this->channel)->kindFor($attachment->mime_type);

        if ($kind === null || ($attachment->size_bytes !== null && $attachment->size_bytes > $kind->maxBytes)) {
            throw new MetaGraphException("Este archivo no cumple los límites de {$this->channel->label()} (tipo o tamaño).", rejected: true, kind: MetaGraphException::INVALID_REQUEST);
        }

        $type = match ($message->type) {
            MessageType::Image, MessageType::Sticker => 'image',
            MessageType::Audio => 'audio',
            MessageType::Video => 'video',
            default => 'file',
        };

        return [
            'type' => $type,
            'payload' => [
                'url' => URL::temporarySignedRoute('meta.media', now()->addMinutes(self::MEDIA_URL_MINUTES), ['attachment' => $attachment->id]),
                'is_reusable' => false,
            ],
        ];
    }

    /**
     * Text that came with a file, sent right after it. The file already
     * reached the customer, so a failure here never fails the message (and
     * is never retried automatically): it returns the Spanish warning shown
     * to the agent on the message instead.
     *
     * @param  array<string, mixed>  $envelope
     */
    private function caption(Message $message, string $text, string $accountId, string $token, array $envelope): ?string
    {
        if ($text === '') {
            return null;
        }

        try {
            $this->client->sendMessage($this->channel, $accountId, $token, [...$envelope, 'message' => ['text' => $text]]);
        } catch (MetaGraphException $exception) {
            Log::warning('The text of an attachment could not be sent after the file.', ['message_id' => $message->id, 'kind' => $exception->kind]);

            return $exception->isAmbiguous()
                ? "El archivo se envió, pero {$this->channel->label()} no confirmó si el texto que lo acompañaba llegó al cliente. Revisa la conversación en Meta antes de escribirlo de nuevo."
                : "El archivo se envió, pero el texto que lo acompañaba no llegó al cliente por {$this->channel->label()}. Escríbelo de nuevo en un mensaje aparte.";
        }

        return null;
    }

    /**
     * A file the channel cannot carry (ZIP) goes as a text with its signed
     * download link: the same link on every attempt, never once it expired
     * or was revoked, and on Instagram in the compact form that fits its
     * 1000-byte limit.
     *
     * @param  array<string, mixed>  $envelope
     */
    private function sendLink(Message $message, MessageAttachment $attachment, string $accountId, string $token, array $envelope): string
    {
        if (AttachmentDownloadLink::isRevoked($message)) {
            throw new MetaGraphException('El enlace de descarga de este archivo fue revocado; no se volverá a enviar.', rejected: true, kind: MetaGraphException::INVALID_REQUEST);
        }

        if (AttachmentDownloadLink::isExpired($message)) {
            throw new MetaGraphException('El enlace de descarga del archivo ya venció; vuelve a adjuntarlo en un mensaje nuevo.', rejected: true, kind: MetaGraphException::INVALID_REQUEST);
        }

        $limit = $this->channel === ConnectionChannel::Instagram ? self::TEXT_LIMITS['instagram'] : null;
        $text = $this->links->text($message, $attachment, $limit);

        if ($limit !== null && strlen($text) > $limit) {
            throw new MetaGraphException('El texto que acompaña al archivo es demasiado largo para Instagram junto con el enlace de descarga (máximo 1000 bytes en total). Envía el texto en un mensaje aparte y vuelve a adjuntar el archivo sin texto.', rejected: true, kind: MetaGraphException::INVALID_REQUEST);
        }

        $this->assertTextLength($text);

        return $this->client->sendMessage($this->channel, $accountId, $token, [...$envelope, 'message' => ['text' => $text]]);
    }

    private function assertTextLength(string $text): void
    {
        $limit = self::TEXT_LIMITS[$this->channel->value] ?? 2000;
        $length = $this->channel === ConnectionChannel::Instagram ? strlen($text) : mb_strlen($text);

        if (trim($text) === '' || $length > $limit) {
            throw new MetaGraphException($this->channel === ConnectionChannel::Instagram
                ? 'Instagram solo acepta textos de hasta 1000 bytes (unos 1000 caracteres sin acentos ni emojis). Divide el mensaje.'
                : 'Messenger solo acepta textos de hasta 2000 caracteres. Divide el mensaje.', rejected: true, kind: MetaGraphException::INVALID_REQUEST);
        }
    }
}
