<?php

namespace App\Http\Requests\Admin;

use App\Enums\HistoryImportDecision;
use App\Models\DataTransfer;
use App\Policies\HistoryImportPolicy;
use App\Services\Migration\History\HistoryInventory;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A bulk decision on a selection (ids) or on everything a filter matches.
 * "undecided" sends the conversations back to undecided; save_contact
 * marks or unmarks their contacts to be saved to the directory.
 *
 * @phpstan-import-type InventoryFilters from HistoryInventory
 */
class HistoryImportBulkRequest extends FormRequest
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
            'decision' => ['nullable', 'required_without:save_contact', 'string', Rule::in([...HistoryImportDecision::values(), HistoryInventory::UNDECIDED])],
            'save_contact' => ['nullable', 'boolean'],
            'ids' => ['nullable', 'array', 'max:5000'],
            'ids.*' => ['integer', 'min:1'],
            // An empty filter ({}) means every conversation of the batch.
            'filters' => ['nullable', 'array'],
            ...HistoryImportFilters::rules('filters.'),
        ];
    }

    /**
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! is_array($this->input('ids')) && ! is_array($this->input('filters'))) {
                $validator->errors()->add('ids', 'Elige conversaciones o un filtro.');
            }
        }];
    }

    public function decision(): ?HistoryImportDecision
    {
        $value = $this->input('decision');

        return is_string($value) ? HistoryImportDecision::tryFrom($value) : null;
    }

    public function hasDecision(): bool
    {
        return is_string($this->input('decision'));
    }

    /**
     * @return list<int>|null
     */
    public function ids(): ?array
    {
        $ids = $this->input('ids');

        return is_array($ids) ? array_values(array_map('intval', $ids)) : null;
    }

    /**
     * @return InventoryFilters|null
     */
    public function filters(): ?array
    {
        $filters = $this->input('filters');

        return $this->ids() === null && is_array($filters) ? HistoryImportFilters::normalize($filters) : null;
    }

    public function saveContact(): ?bool
    {
        return $this->has('save_contact') && $this->input('save_contact') !== null ? $this->boolean('save_contact') : null;
    }
}
