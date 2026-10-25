<?php

namespace App\Http\Requests\Conversations;

use App\Concerns\AuthorizesVisibleConversation;
use App\Models\Conversation;
use App\Support\Attachments\ChannelAttachmentPolicy;
use App\Support\Attachments\ChannelAttachmentRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An ordinary reply: text, or one file with an optional caption. There is
 * deliberately no field to choose a template or another message type: the
 * type of a file is decided by its real content. Authorization, the
 * customer service window and the per type limits are checked by the
 * engine; here the upload is only capped at the largest file the
 * conversation's channel accepts (ChannelAttachmentPolicy).
 *
 * A file larger than PHP's upload_max_filesize never reaches Laravel (the
 * upload fails with UPLOAD_ERR_INI_SIZE): it is answered with a clear
 * explanation instead of a generic "failed to upload".
 */
class OutboundMessageRequest extends FormRequest
{
    use AuthorizesVisibleConversation;

    /** WhatsApp's limit for the body of a text message. */
    public const BODY_MAX_LENGTH = 4096;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'required_without:attachment', 'string', 'max:'.self::BODY_MAX_LENGTH],
            'client_reference' => ['required', 'uuid'],
            'attachment' => ['nullable', 'file', 'max:'.(int) ceil($this->uploadCapBytes() / 1024)],
            'reply_to_message_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required_without' => 'Escribe un mensaje o adjunta un archivo.',
            'attachment.max' => sprintf('El archivo pesa más de %s, el máximo que se puede enviar por esta conversación.', ChannelAttachmentRules::humanSize($this->uploadCapBytes())),
            'attachment.uploaded' => self::serverRejectedUpload(),
        ];
    }

    /**
     * Why the server refused a file before the application could read it:
     * PHP's upload_max_filesize (or a partial or interrupted upload).
     */
    public static function serverRejectedUpload(): string
    {
        $limit = trim((string) ini_get('upload_max_filesize'));

        return 'El servidor no pudo recibir el archivo'.($limit !== '' ? " (su límite de subida es {$limit}B)" : '').'. Intenta con un archivo más pequeño o pide a un administrador que amplíe el límite de subida del servidor.';
    }

    /**
     * The largest file the conversation's channel accepts; the largest of
     * every channel when the conversation has no connection.
     */
    private function uploadCapBytes(): int
    {
        $policy = app(ChannelAttachmentPolicy::class);
        $conversation = $this->route('conversation');
        $channel = $conversation instanceof Conversation ? $conversation->channelConnection?->channel : null;

        return $channel === null ? $policy->hardCapBytes() : $policy->forChannel($channel)->maxBytes();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'body' => 'mensaje',
            'client_reference' => 'referencia del mensaje',
            'attachment' => 'archivo adjunto',
            'reply_to_message_id' => 'mensaje citado',
        ];
    }
}
