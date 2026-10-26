<?php

namespace App\Http\Requests\Admin;

use App\Policies\HistoryImportPolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A new Whaticket history import batch.
 */
class HistoryImportBatchRequest extends FormRequest
{
    public function authorize(HistoryImportPolicy $policy): Response
    {
        return $policy->create($this->user());
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:120'],
            'source_instance' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9 _.\-]+$/'],
            'timezone' => ['nullable', 'timezone:all'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'source_instance.regex' => 'Usa solo letras, números, espacios, guiones o puntos.',
            'timezone.timezone' => 'Elige una zona horaria válida.',
        ];
    }
}
