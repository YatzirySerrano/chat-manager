<?php

namespace App\Http\Requests\Admin;

use App\Models\Department;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the automatic messages of a department (welcome and
 * out-of-hours). Each field is optional so either card can be saved alone.
 * Uses the same authorization as editing the department.
 */
class UpdateDepartmentOperationsRequest extends FormRequest
{
    /**
     * Longest welcome message accepted; kept well under the WhatsApp text limit.
     */
    public const WELCOME_MESSAGE_MAX_LENGTH = 1024;

    public function authorize(): bool
    {
        $department = $this->route('department');

        return $department instanceof Department
            && ($this->user()?->can('update', $department) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'welcome_message' => ['sometimes', 'nullable', 'string', 'max:'.self::WELCOME_MESSAGE_MAX_LENGTH],
            'away_message' => ['sometimes', 'nullable', 'string', 'max:'.self::WELCOME_MESSAGE_MAX_LENGTH],
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'welcome_message' => 'mensaje de bienvenida',
            'away_message' => 'mensaje fuera de horario',
        ];
    }

    /**
     * @return array{welcome_message?: string|null, away_message?: string|null}
     */
    public function payload(): array
    {
        $payload = [];

        foreach (['welcome_message', 'away_message'] as $field) {
            if ($this->has($field)) {
                $message = $this->string($field)->trim()->value();
                $payload[$field] = $message === '' ? null : $message;
            }
        }

        return $payload;
    }
}
