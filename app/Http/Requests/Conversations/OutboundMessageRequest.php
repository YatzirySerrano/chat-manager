<?php

namespace App\Http\Requests\Conversations;

use App\Concerns\AuthorizesVisibleConversation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An ordinary reply: text, or one file with an optional caption. There is
 * deliberately no field to choose a template or another message type: the
 * type of a file is decided by its real content. Authorization, the
 * customer service window and the media limits are checked by the engine.
 */
class OutboundMessageRequest extends FormRequest
{
    use AuthorizesVisibleConversation;

    /** WhatsApp's limit for the body of a text message. */
    public const BODY_MAX_LENGTH = 4096;

    /** Upper bound of any upload (KB) before the per type limits apply. */
    public const UPLOAD_MAX_KILOBYTES = 102400;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'required_without:attachment', 'string', 'max:'.self::BODY_MAX_LENGTH],
            'client_reference' => ['required', 'uuid'],
            'attachment' => ['nullable', 'file', 'max:'.self::UPLOAD_MAX_KILOBYTES],
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
        ];
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
