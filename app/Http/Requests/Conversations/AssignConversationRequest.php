<?php

namespace App\Http\Requests\Conversations;

use Illuminate\Contracts\Validation\ValidationRule;

class AssignConversationRequest extends ConversationOperationRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'user_id' => ['required', 'integer'],
            'confirm_over_capacity' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [...parent::attributes(), 'user_id' => 'agente', 'confirm_over_capacity' => 'confirmación'];
    }

    /**
     * Whether the actor explicitly confirmed handing the conversation to an
     * agent who already attends their maximum of conversations.
     */
    public function confirmsOverCapacity(): bool
    {
        return $this->boolean('confirm_over_capacity');
    }
}
