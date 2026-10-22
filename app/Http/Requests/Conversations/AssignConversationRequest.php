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
            'confirm_unavailable' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [...parent::attributes(), 'user_id' => 'agente', 'confirm_unavailable' => 'confirmación'];
    }

    /**
     * Whether the actor explicitly confirmed handing the conversation to an
     * agent declared unavailable.
     */
    public function confirmsUnavailable(): bool
    {
        return $this->boolean('confirm_unavailable');
    }
}
