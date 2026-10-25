<?php

namespace App\Services\Meta;

use App\Enums\AttachmentStatus;
use App\Enums\MessageType;
use App\Models\Connection;
use App\Models\ContactIdentity;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Support\Attachments\AttachmentDownloadLink;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Storage;

/**
 * Real WhatsApp Cloud API transport: POST /{phone-number-id}/messages with the
 * connection's own token. Media is first uploaded to WhatsApp from the
 * private disk (POST /{phone-number-id}/media) and then sent by id; the
 * upload id is kept on the attachment so a retry never uploads it again.
 * A file WhatsApp has no type for (ZIP, delivery method "link") is sent as
 * a text with a signed expiring download link (AttachmentDownloadLink).
 * The message's client reference travels as biz_opaque_callback_data, so
 * Meta's status webhooks can be matched even if the answer to this call
 * never arrived. During a controlled pilot (WHATSAPP_PILOT_RECIPIENTS) only
 * the listed recipients are reached.
 */
class MetaCloudWhatsAppTransport implements WhatsAppTransport
{
    public function __construct(private MetaGraphClient $graph) {}

    public function name(): string
    {
        return 'meta';
    }

    public function send(Message $message): SendResult
    {
        $message->loadMissing(['conversation.channelConnection', 'conversation.contact', 'replyTo', 'attachments']);
        $connection = $message->conversation->channelConnection;

        if ($connection === null || blank($connection->phone_number_id) || blank($connection->access_token)) {
            throw new WhatsAppTransportUnavailable('La conexión no tiene el Phone number ID o el token de acceso guardados.');
        }

        $recipient = self::recipientNumber($this->whatsAppId($message));
        $pilot = self::pilotRecipients();

        // Before any upload or send: nothing reaches Meta for other recipients.
        // The pilot list may hold either form of a Mexican mobile number.
        if ($pilot !== [] && ! in_array($recipient, array_map(self::recipientNumber(...), $pilot), true)) {
            throw new WhatsAppTransportUnavailable('El destinatario no está en la lista del piloto de WhatsApp (WHATSAPP_PILOT_RECIPIENTS); el mensaje no se envió.');
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $recipient,
            ...$this->content($message, $connection),
            'biz_opaque_callback_data' => self::callbackData($message),
        ];

        $replyTo = $message->replyTo?->whatsapp_message_id;

        if (is_string($replyTo) && $replyTo !== '') {
            $payload['context'] = ['message_id' => $replyTo];
        }

        $response = $this->graph->post((string) $connection->access_token, rawurlencode((string) $connection->phone_number_id).'/messages', $payload, 'el envío del mensaje');
        $id = $response['messages'][0]['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new MetaGraphException('Meta respondió sin el identificador del mensaje; no es posible confirmar el envío.', rejected: false, kind: MetaGraphException::AMBIGUOUS);
        }

        return new SendResult($id);
    }

    /**
     * Recipients of the optional controlled pilot (config whatsapp.pilot);
     * empty when every recipient is allowed.
     *
     * @return list<string>
     */
    public static function pilotRecipients(): array
    {
        return array_values(array_filter((array) config('whatsapp.pilot.recipients', []), fn (mixed $number): bool => is_string($number) && $number !== ''));
    }

    /**
     * Opaque reference echoed back by Meta in every status of the message.
     */
    public static function callbackData(Message $message): string
    {
        return $message->client_reference ?? 'message-'.$message->id;
    }

    /**
     * The number written in "to" for a WhatsApp ID. Meta keeps Mexico's
     * legacy mobile "1" in the WhatsApp ID of Mexican numbers (521 + 10
     * digits) although the numbering plan no longer uses it; Meta delivers
     * either form to the same account, but the allowed list of a test number
     * only matches the current one (52 + 10 digits) and answers 131030
     * otherwise. Only that case is rewritten, with the same rule used to
     * store the contact's phone (PhoneNumber::normalize); any other WhatsApp
     * ID, from any country, is sent exactly as Meta reported it.
     */
    private static function recipientNumber(string $whatsAppId): string
    {
        if (! ctype_digit($whatsAppId)) {
            return $whatsAppId;
        }

        $normalized = PhoneNumber::normalize('+'.$whatsAppId);

        return $normalized === null ? $whatsAppId : substr($normalized, 1);
    }

    /**
     * The contact's most recently seen WhatsApp identity, or the digits of
     * its phone when it has none.
     */
    private function whatsAppId(Message $message): string
    {
        $identity = ContactIdentity::query()
            ->where('contact_id', $message->conversation->contact_id)
            ->where('channel', 'whatsapp')
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->value('external_id');

        $recipient = is_string($identity) && $identity !== ''
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
    private function content(Message $message, Connection $connection): array
    {
        return match ($message->type) {
            MessageType::Text => ['type' => 'text', 'text' => ['body' => (string) $message->body, 'preview_url' => false]],
            MessageType::Template => ['type' => 'template', 'template' => $this->template($message)],
            MessageType::Image, MessageType::Video, MessageType::Audio, MessageType::Document, MessageType::Sticker => $this->media($message, $connection),
            default => throw new MetaGraphException('Este tipo de mensaje no se puede enviar por WhatsApp.', rejected: true, kind: MetaGraphException::INVALID_REQUEST),
        };
    }

    /**
     * @return array{name: string, language: array{code: string}, components?: list<array<string, mixed>>}
     */
    private function template(Message $message): array
    {
        $template = $message->metadata['template'] ?? null;

        if (! is_array($template) || ! is_string($template['name'] ?? null) || ! is_string($template['language'] ?? null)) {
            throw new MetaGraphException('El mensaje de plantilla no tiene su nombre e idioma.', rejected: true, kind: MetaGraphException::INVALID_REQUEST);
        }

        $components = is_array($template['components'] ?? null) ? array_values($template['components']) : [];

        return [
            'name' => $template['name'],
            'language' => ['code' => $template['language']],
            ...($components === [] ? [] : ['components' => $components]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function media(Message $message, Connection $connection): array
    {
        $attachment = $message->attachments->first();

        if (! $attachment instanceof MessageAttachment || $attachment->status !== AttachmentStatus::Available) {
            throw new MetaGraphException('El archivo adjunto no está disponible para enviarlo.', rejected: true, kind: MetaGraphException::INVALID_REQUEST);
        }

        // WhatsApp has no type for this file (ZIP): a text with a signed
        // expiring download link instead of an upload.
        if (AttachmentDownloadLink::attachmentOf($message) !== null) {
            if (AttachmentDownloadLink::isRevoked($message)) {
                throw new MetaGraphException('El enlace de descarga de este archivo fue revocado; no se volverá a enviar.', rejected: true, kind: MetaGraphException::INVALID_REQUEST);
            }

            if (AttachmentDownloadLink::isExpired($message)) {
                throw new MetaGraphException('El enlace de descarga del archivo ya venció; vuelve a adjuntarlo en un mensaje nuevo.', rejected: true, kind: MetaGraphException::INVALID_REQUEST);
            }

            return ['type' => 'text', 'text' => ['body' => app(AttachmentDownloadLink::class)->text($message, $attachment), 'preview_url' => false]];
        }

        $mediaId = $attachment->whatsapp_media_id ?? $this->upload($attachment, $connection);
        $type = $message->type->value;
        $media = ['id' => $mediaId];
        $caption = trim((string) $message->body);

        if ($caption !== '' && in_array($type, ['image', 'video', 'document'], true)) {
            $media['caption'] = $caption;
        }

        if ($type === 'document' && filled($attachment->file_name)) {
            $media['filename'] = (string) $attachment->file_name;
        }

        return ['type' => $type, $type => $media];
    }

    private function upload(MessageAttachment $attachment, Connection $connection): string
    {
        $disk = Storage::disk($attachment->disk);

        if ($attachment->path === null || ! $disk->exists($attachment->path)) {
            throw new MetaGraphException('El archivo adjunto ya no existe en el almacenamiento.', rejected: true, kind: MetaGraphException::INVALID_REQUEST);
        }

        $stream = $disk->readStream($attachment->path);

        if ($stream === null) {
            throw new MetaGraphException('No fue posible leer el archivo adjunto.', rejected: true, kind: MetaGraphException::INVALID_REQUEST);
        }

        try {
            $mediaId = $this->graph->upload((string) $connection->access_token, (string) $connection->phone_number_id, $stream, $attachment->mime_type, $attachment->file_name ?? basename($attachment->path));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $attachment->forceFill(['whatsapp_media_id' => $mediaId])->save();

        return $mediaId;
    }
}
