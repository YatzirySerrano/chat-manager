<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Window of GET /api/v1/conversations/{conversation}/messages: messages
 * after or before a message id (the cursors of the list), oldest first.
 */
class ListMessagesRequest extends FormRequest
{
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
            'after' => ['nullable', 'integer', 'min:0', 'prohibits:before'],
            'before' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('integrations.pagination.max', 100)],
        ];
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? config('integrations.pagination.default', 25));
    }
}
