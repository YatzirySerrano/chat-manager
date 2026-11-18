<?php

namespace App\Http\Requests\Admin;

use App\Services\Conversations\MissingInformation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Catalog of «Información faltante»: the items the agent picks when marking
 * a request as «Necesita información» and the first line of the message the
 * customer receives. Items are unique (ignoring case) and keep their order.
 */
class UpdateMissingInfoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update-settings') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $items = $this->input('items');

        if (is_array($items)) {
            $this->merge(['items' => array_map(fn (mixed $item): mixed => is_string($item) ? trim(preg_replace('/\s+/u', ' ', $item) ?? $item) : $item, $items)]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'intro' => ['required', 'string', 'max:'.MissingInformation::INTRO_MAX_LENGTH],
            'items' => ['present', 'array', 'max:'.MissingInformation::MAX_ITEMS],
            'items.*' => ['required', 'string', 'max:'.MissingInformation::ITEM_MAX_LENGTH, 'distinct:ignore_case'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['intro' => 'mensaje para el cliente', 'items' => 'información faltante', 'items.*' => 'opción'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'intro.required' => 'Escribe el mensaje con el que empieza la lista.',
            'items.max' => 'El catálogo admite hasta '.MissingInformation::MAX_ITEMS.' opciones.',
            'items.*.required' => 'Escribe el nombre de la opción.',
            'items.*.max' => 'El nombre no puede tener más de '.MissingInformation::ITEM_MAX_LENGTH.' caracteres.',
            'items.*.distinct' => 'Esta opción ya está en la lista.',
        ];
    }

    /**
     * @return array{intro: string, items: list<string>}
     */
    public function payload(): array
    {
        return [
            'intro' => trim($this->string('intro')->value()),
            'items' => array_values(array_map('strval', (array) $this->input('items', []))),
        ];
    }
}
