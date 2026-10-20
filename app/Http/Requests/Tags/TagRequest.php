<?php

namespace App\Http\Requests\Tags;

use App\Enums\ColorSwatch;
use App\Models\Tag;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validation for creating and updating tags. Names are unique regardless of
 * letter case, which the case-insensitive collation of the column enforces.
 */
class TagRequest extends FormRequest
{
    public const DUPLICATE_NAME_MESSAGE = 'Ya existe una etiqueta con ese nombre (no se distinguen mayúsculas de minúsculas).';

    public function authorize(): bool
    {
        $tag = $this->route('tag');

        return $tag instanceof Tag
            ? ($this->user()?->can('update', $tag) ?? false)
            : ($this->user()?->can('create', Tag::class) ?? false);
    }

    /**
     * Collapse repeated whitespace so "VIP  Oro" and "VIP Oro" are the same name.
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
        $tag = $this->route('tag');

        return [
            'name' => [
                'required',
                'string',
                'max:60',
                Rule::unique(Tag::class)->ignore($tag instanceof Tag ? $tag->id : null),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'color' => ['required', Rule::enum(ColorSwatch::class)],
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
     * @return array{name: string, description: string|null, color: ColorSwatch}
     */
    public function payload(): array
    {
        return [
            'name' => $this->string('name')->trim()->value(),
            'description' => $this->filled('description') ? $this->string('description')->trim()->value() : null,
            'color' => $this->enum('color', ColorSwatch::class) ?? ColorSwatch::Slate,
        ];
    }
}
