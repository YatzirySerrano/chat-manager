<?php

namespace App\Http\Requests\Imports;

use App\Models\DataTransfer;
use App\Services\Imports\ContactImportAnalyzer;
use App\Services\Imports\ContactImporter;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Column mapping (field => column index of the file, or null) and the
 * decision for contacts that already exist.
 */
class ConfigureContactImportRequest extends FormRequest
{
    public function authorize(): Response
    {
        $transfer = $this->route('transfer');

        return $transfer instanceof DataTransfer
            ? Gate::forUser($this->user())->inspect('manageImport', $transfer)
            : Response::denyAsNotFound();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'mapping' => ['required', 'array:'.implode(',', array_keys(ContactImportAnalyzer::FIELDS))],
            'decision' => ['required', Rule::in(array_keys(ContactImporter::DECISIONS))],
        ];

        foreach (array_keys(ContactImportAnalyzer::FIELDS) as $field) {
            $rules["mapping.{$field}"] = ['nullable', 'integer', 'min:0', 'max:'.((int) config('imports.max_columns', 30) - 1)];
        }

        return $rules;
    }

    /**
     * @return array<string, int|null>
     */
    public function mapping(): array
    {
        $mapping = [];

        foreach (array_keys(ContactImportAnalyzer::FIELDS) as $field) {
            $value = $this->input("mapping.{$field}");
            $mapping[$field] = $value === null || $value === '' ? null : (int) $value;
        }

        return $mapping;
    }
}
