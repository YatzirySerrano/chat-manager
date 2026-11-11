<?php

namespace App\Http\Requests\Categories;

use App\Enums\ColorSwatch;
use App\Models\ConversationCategory;
use App\Rules\AppColorValue;
use App\Support\AppColor;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validation for creating and updating request categories. Names are unique
 * regardless of letter case, which the case-insensitive collation of the
 * column enforces.
 */
class ConversationCategoryRequest extends FormRequest
{
    public const DUPLICATE_NAME_MESSAGE = 'Ya existe una categoría con ese nombre (no se distinguen mayúsculas de minúsculas).';

    public function authorize(): bool
    {
        $category = $this->route('category');

        return $category instanceof ConversationCategory
            ? ($this->user()?->can('update', $category) ?? false)
            : ($this->user()?->can('create', ConversationCategory::class) ?? false);
    }

    /**
     * Collapse repeated whitespace so "Soporte  técnico" and "Soporte técnico" are the same name.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => Str::squish($this->input('name'))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $category = $this->route('category');

        return [
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique(ConversationCategory::class)->ignore($category instanceof ConversationCategory ? $category->id : null),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'color' => ['required', 'string', new AppColorValue],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => self::DUPLICATE_NAME_MESSAGE,
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
