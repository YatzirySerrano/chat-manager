<?php

namespace App\Http\Requests\Admin;

use App\Enums\ColorSwatch;
use App\Models\Department;
use App\Rules\AppColorValue;
use App\Support\AppColor;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Validation for creating and updating departments.
 */
class DepartmentRequest extends FormRequest
{
    public function authorize(): Response
    {
        $department = $this->route('department');

        return $department instanceof Department
            ? Gate::inspect('update', $department)
            : Gate::inspect('create', Department::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $department = $this->route('department');

        return [
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique(Department::class)->ignore($department instanceof Department ? $department->id : null),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'color' => ['required', 'string', new AppColorValue],
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
            'name' => 'nombre',
            'description' => 'descripción',
            'color' => 'color',
        ];
    }

    /**
     * @return array{name: string, description: string|null, color: AppColor}
     */
    public function payload(): array
    {
        return [
            'name' => $this->string('name')->trim()->value(),
            'description' => $this->filled('description') ? $this->string('description')->trim()->value() : null,
            'color' => AppColor::tryFrom($this->input('color')) ?? AppColor::from(ColorSwatch::Slate),
        ];
    }
}
