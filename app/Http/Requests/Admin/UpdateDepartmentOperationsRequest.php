<?php

namespace App\Http\Requests\Admin;

use App\Models\Department;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the operational configuration of a department (its welcome
 * message). Uses the same authorization as editing the department.
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
            'welcome_message' => ['nullable', 'string', 'max:'.self::WELCOME_MESSAGE_MAX_LENGTH],
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
        ];
    }

    /**
     * @return array{welcome_message: string|null}
     */
    public function payload(): array
    {
        $message = $this->string('welcome_message')->trim()->value();

        return [
            'welcome_message' => $message === '' ? null : $message,
        ];
    }
}
