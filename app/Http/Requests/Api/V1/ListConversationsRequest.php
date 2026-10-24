<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ConversationStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters of GET /api/v1/conversations. The token was already authenticated
 * and authorized by the route middleware; the filters only narrow what the
 * token can reach.
 */
class ListConversationsRequest extends FormRequest
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
            'status' => ['nullable', 'string', Rule::enum(ConversationStatus::class)],
            'connection_id' => ['nullable', 'integer', 'min:1'],
            'department_id' => ['nullable', 'integer', 'min:1'],
            'updated_since' => ['nullable', 'date'],
            'cursor' => ['nullable', 'string', 'max:1024'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('integrations.pagination.max', 100)],
        ];
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? config('integrations.pagination.default', 25));
    }
}
