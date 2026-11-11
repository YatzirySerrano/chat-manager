<?php

namespace App\Http\Requests\Conversations;

use App\Concerns\AuthorizesVisibleConversation;
use App\Services\Conversations\QueueRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Common input of conversation operations: the version the user was looking
 * at (so a decision on stale data is rejected) and an optional reason.
 * Authorization happens in the engine, under lock, with fresh data.
 */
class ConversationOperationRequest extends FormRequest
{
    use AuthorizesVisibleConversation;

    public const REASON_MAX_LENGTH = 255;

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
            'override_reason' => 'motivo de la excepción',
        ];
    }

    public function version(): int
    {
        return $this->integer('version');
    }

    /**
     * Rule of the justification of an exception to the queue rules, for the
     * operations that take a request from its queue (accept, assign,
     * transfer to an agent). The engine ignores it without the permission.
     *
     * @return array<string, list<string>>
     */
    public static function overrideReasonRules(): array
    {
        return ['override_reason' => ['nullable', 'string', 'min:'.QueueRules::OVERRIDE_REASON_MIN, 'max:'.self::REASON_MAX_LENGTH]];
    }

    public function overrideReason(): ?string
    {
        $reason = $this->string('override_reason')->squish()->value();

        return $reason === '' ? null : $reason;
    }

    public function reason(): ?string
    {
        $reason = $this->string('reason')->squish()->value();

        return $reason === '' ? null : $reason;
    }
}
