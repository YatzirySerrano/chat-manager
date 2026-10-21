<?php

namespace App\Http\Requests\Conversations;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An ordinary text reply. There is deliberately no field to choose a
 * template or another message type. Authorization and the customer service
 * window are checked by the engine.
 */
class OutboundMessageRequest extends FormRequest
{
    /** WhatsApp's limit for the body of a text message. */
    public const BODY_MAX_LENGTH = 4096;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.self::BODY_MAX_LENGTH],
            'client_reference' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['body' => 'mensaje', 'client_reference' => 'referencia del mensaje'];
    }
}
