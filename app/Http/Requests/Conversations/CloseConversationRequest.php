<?php

namespace App\Http\Requests\Conversations;

use App\Enums\ConversationOutcome;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Closing a conversation, usually as «Solicitud completada». On top of the
 * common input it may carry the credit result (optional: clients that do
 * not send it keep completing exactly as before).
 */
class CloseConversationRequest extends ConversationOperationRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            ...self::outcomeRules(),
        ];
    }

    /**
     * The credit result and the authorized amount (an approval only; up to
     * two decimals, without separators: «3000» or «3000.50»).
     *
     * @return array<string, list<mixed>>
     */
    public static function outcomeRules(): array
    {
        return [
            'outcome' => ['nullable', 'string', Rule::enum(ConversationOutcome::class)],
            'outcome_amount' => ['nullable', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function outcomeAttributes(): array
    {
        return [
            'outcome' => 'resultado',
            'outcome_amount' => 'monto autorizado',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function outcomeMessages(): array
    {
        return [
            'outcome_amount.regex' => 'Escribe el monto autorizado solo con números, por ejemplo 3000 o 3000.50.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [...parent::attributes(), ...self::outcomeAttributes()];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::outcomeMessages();
    }

    public function outcome(): ?ConversationOutcome
    {
        return $this->filled('outcome') ? ConversationOutcome::tryFrom($this->string('outcome')->value()) : null;
    }

    public function outcomeAmount(): ?string
    {
        return $this->filled('outcome_amount') ? $this->string('outcome_amount')->value() : null;
    }
}
