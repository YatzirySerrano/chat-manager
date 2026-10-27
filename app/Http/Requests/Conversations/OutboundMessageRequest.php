<?php

namespace App\Http\Requests\Conversations;

use App\Concerns\AuthorizesVisibleConversation;
use App\Exceptions\AttachmentRejected;
use App\Models\Conversation;
use App\Support\Attachments\ChannelAttachmentPolicy;
use App\Support\Attachments\ChannelAttachmentRules;
use App\Support\Attachments\ServerUploadLimits;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * An ordinary reply: text, or one file with an optional caption. There is
 * deliberately no field to choose a template or another message type: the
 * type of a file is decided by its real content. Authorization, the
 * customer service window and the per type limits are checked by the
 * engine; here the upload is only capped at the largest file the
 * conversation's channel accepts (ChannelAttachmentPolicy).
 *
 * A file PHP refused (larger than upload_max_filesize, a partial upload, no
 * temporary directory) still counts as "a file was sent": the reply is
 * refused only on the attachment field, with the cause and a stable code
 * (ServerUploadLimits, AttachmentRejected), never with "write a message".
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
            'body' => ['nullable', Rule::requiredIf(fn (): bool => $this->sentAttachment() === null), 'string', 'max:'.self::BODY_MAX_LENGTH],
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
            'body.required' => 'Escribe un mensaje o adjunta un archivo.',
            'attachment.max' => sprintf('El archivo pesa más de %s, el máximo que se puede enviar por esta conversación.', ChannelAttachmentRules::humanSize($this->uploadCapBytes())),
            'attachment.uploaded' => $this->uploadFailure()['message'] ?? 'No se pudo recibir el archivo. Inténtalo de nuevo.',
        ];
    }

    /**
     * A file PHP refused is answered with its own cause and code only.
     */
    protected function failedValidation(Validator $validator): void
    {
        $failure = $this->uploadFailure();

        if ($failure !== null && $validator->errors()->has('attachment')) {
            throw AttachmentRejected::fromValidator($validator, $failure['code'])
                ->errorBag($this->errorBag)
                ->redirectTo($this->getRedirectUrl());
        }

        parent::failedValidation($validator);
    }

    /**
     * The file field as PHP delivered it, also when the upload failed
     * (UPLOAD_ERR_NO_FILE means nothing was chosen).
     */
    public function sentAttachment(): ?UploadedFile
    {
        $file = $this->file('attachment');

        return $file instanceof UploadedFile && $file->getError() !== UPLOAD_ERR_NO_FILE ? $file : null;
    }

    /**
     * @return array{code: string, message: string}|null
     */
    private function uploadFailure(): ?array
    {
        $file = $this->sentAttachment();

        if ($file === null) {
            return null;
        }

        $conversation = $this->route('conversation');

        return app(ServerUploadLimits::class)->failure($file, $conversation instanceof Conversation ? $conversation->channelConnection?->channel->label() : null);
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
