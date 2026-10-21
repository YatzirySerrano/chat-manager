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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [...parent::attributes(), 'user_id' => 'agente'];
    }
}
