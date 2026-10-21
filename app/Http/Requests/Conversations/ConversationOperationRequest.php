<?php

namespace App\Http\Requests\Conversations;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Common input of conversation operations: the version the user was looking
 * at (so a decision on stale data is rejected) and an optional reason.
 * Authorization happens in the engine, under lock, with fresh data.
 */
class ConversationOperationRequest extends FormRequest
{
    public const REASON_MAX_LENGTH = 255;

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
            'version' => ['required', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:'.self::REASON_MAX_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'version' => 'versión',
            'reason' => 'motivo',
        ];
    }

    public function version(): int
    {
        return $this->integer('version');
    }

    public function reason(): ?string
    {
        $reason = $this->string('reason')->squish()->value();

        return $reason === '' ? null : $reason;
    }
}
