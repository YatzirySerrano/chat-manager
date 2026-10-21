<?php

namespace App\Http\Requests\Conversations;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Text of an internal note. Stored as plain text and always rendered
 * escaped. Authorization happens in the engine.
 */
class ConversationNoteRequest extends FormRequest
{
    public const BODY_MAX_LENGTH = 2000;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('body'))) {
            $this->merge(['body' => trim(preg_replace('/[^\P{C}\n\t]/u', '', $this->input('body')) ?? '')]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.self::BODY_MAX_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['body' => 'nota'];
    }
}
