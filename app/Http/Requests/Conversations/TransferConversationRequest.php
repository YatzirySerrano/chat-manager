<?php

namespace App\Http\Requests\Conversations;

use Illuminate\Contracts\Validation\ValidationRule;

class TransferConversationRequest extends ConversationOperationRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'department_id' => ['nullable', 'integer', 'required_without:user_id'],
            'user_id' => ['nullable', 'integer', 'required_without:department_id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [...parent::attributes(), 'department_id' => 'departamento', 'user_id' => 'agente'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'department_id.required_without' => 'Elige el departamento o el agente que recibirá la conversación.',
            'user_id.required_without' => 'Elige el departamento o el agente que recibirá la conversación.',
        ];
    }
}
