<?php

namespace App\Http\Requests\Conversations;

use App\Actions\Conversations\SetConversationReadiness;
use App\Concerns\AuthorizesVisibleConversation;
use App\Enums\ConversationReadiness;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Confirming a request as complete or returning it to «Necesita
 * información». The reason is required only when returning it. The version
 * the user was looking at is optional. Authorization happens in the engine,
 * under lock, with fresh data.
 */
class ConversationReadinessRequest extends FormRequest
{
    use AuthorizesVisibleConversation;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'readiness' => ['required', Rule::enum(ConversationReadiness::class)],
            'reason' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->input('readiness') === ConversationReadiness::NeedsInfo->value),
                'string',
                'min:'.SetConversationReadiness::REASON_MIN,
                'max:'.ConversationOperationRequest::REASON_MAX_LENGTH,
            ],
            'version' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['readiness' => 'estado de la solicitud', 'reason' => 'motivo', 'version' => 'versión'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['reason.required' => 'Indica qué información falta.'];
    }

    public function readiness(): ConversationReadiness
    {
        return ConversationReadiness::from($this->string('readiness')->value());
    }

    public function reason(): ?string
    {
        $reason = $this->string('reason')->squish()->value();

        return $reason === '' ? null : $reason;
    }

    public function version(): ?int
    {
        return $this->filled('version') ? $this->integer('version') : null;
    }
}
