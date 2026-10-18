<?php

namespace App\Http\Requests\Admin;

use App\Enums\ThemePalette;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAppearanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update-appearance') ?? false;
    }

    /**
     * Only curated palettes are accepted; arbitrary colors or styles are not stored.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'palette' => ['required', Rule::enum(ThemePalette::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['palette' => 'paleta de color'];
    }
}
