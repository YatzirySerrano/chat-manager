<?php

namespace App\Http\Requests\Admin;

use App\Models\Connection;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared validation for activating or deactivating a resource. The resource
 * policy is checked before the input is validated, so somebody without
 * access never learns anything from validation errors; the controller
 * authorizes again.
 */
class UpdateStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $connection = $this->route('connection');

        return ! $connection instanceof Connection
            || ($this->user()?->can('changeStatus', $connection) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'is_active' => ['required', 'boolean'],
        ];
    }
}
