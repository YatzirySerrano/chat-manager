<?php

namespace App\Http\Requests\WebChat;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/widget/session/messages. The body is plain text: control
 * characters (except line breaks and tabs) are removed, line breaks are
 * normalized and the text is trimmed before its length is checked.
 * "website" is a honeypot the widget never fills: any value is refused.
 */
class StoreVisitorMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $body = $this->input('body');

        if (is_string($body)) {
            $body = str_replace(["\r\n", "\r"], "\n", $body);
            $body = preg_replace('/[^\P{Cc}\n\t]|[\p{Cf}]/u', '', $body) ?? '';

            $this->merge(['body' => trim($body)]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'client_message_id' => ['required', 'string', 'uuid'],
            'body' => ['required', 'string', 'max:'.(int) config('webchat.message_max_length', 2000)],
            'website' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['client_message_id' => 'identificador del mensaje', 'body' => 'mensaje', 'website' => 'sitio web'];
    }
}
