<?php

namespace App\Http\Requests\Admin;

use App\Enums\HistoryImportDecision;
use App\Models\DataTransfer;
use App\Policies\HistoryImportPolicy;
use App\Services\Migration\History\HistoryInventory;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A classification rule (preview or apply): inventory filters as criteria,
 * the destination, and whether it overwrites decisions already taken.
 *
 * @phpstan-import-type InventoryFilters from HistoryInventory
 */
class HistoryImportRuleRequest extends FormRequest
{
    public function authorize(HistoryImportPolicy $policy): Response
    {
        /** @var DataTransfer $batch */
        $batch = $this->route('transfer');

        return $policy->classify($this->user(), $batch);
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [$this->routeIs('*.rules.store') ? 'required' : 'nullable', 'string', 'max:120'],
            'criteria' => ['present', 'array'],
            ...HistoryImportFilters::rules('criteria.'),
            'decision' => ['required', 'string', Rule::in(HistoryImportDecision::values())],
            'overwrite' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return InventoryFilters
     */
    public function criteria(): array
    {
        return HistoryImportFilters::normalize((array) $this->input('criteria', []));
    }

    public function decision(): HistoryImportDecision
    {
        return HistoryImportDecision::from($this->string('decision')->value());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Ponle un nombre a la regla para reconocerla después.',
            'criteria.present' => 'Indica qué conversaciones abarca la regla.',
            'decision.in' => 'Elige importar, archivar o no importar.',
        ];
    }
}
