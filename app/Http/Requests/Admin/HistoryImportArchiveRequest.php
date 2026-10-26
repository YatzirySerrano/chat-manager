<?php

namespace App\Http\Requests\Admin;

use App\Policies\HistoryImportPolicy;
use App\Services\Migration\History\HistoryArchive;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters of the archived-history consultation.
 *
 * @phpstan-import-type ArchiveFilters from HistoryArchive
 */
class HistoryImportArchiveRequest extends FormRequest
{
    public function authorize(HistoryImportPolicy $policy): Response
    {
        return $policy->viewArchive($this->user());
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'destination' => ['nullable', 'string', Rule::in([HistoryArchive::DESTINATION_ARCHIVE, HistoryArchive::DESTINATION_IMPORT, HistoryArchive::DESTINATION_ALL])],
            'search' => ['nullable', 'string', 'max:160'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'department_id' => ['nullable', 'integer', 'min:1'],
            'agent_id' => ['nullable', 'integer', 'min:1'],
            'tag_id' => ['nullable', 'integer', 'min:1'],
            'connection_id' => ['nullable', 'integer', 'min:1'],
            'batch_id' => ['nullable', 'integer', 'min:1'],
            'contact_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return ArchiveFilters
     */
    public function filters(): array
    {
        $validated = $this->validated();
        $filters = [];

        foreach (['destination', 'search', 'date_from', 'date_to'] as $key) {
            if (is_string($validated[$key] ?? null) && trim($validated[$key]) !== '') {
                $filters[$key] = trim($validated[$key]);
            }
        }

        foreach (['department_id', 'agent_id', 'tag_id', 'connection_id', 'batch_id', 'contact_id'] as $key) {
            if (isset($validated[$key])) {
                $filters[$key] = (int) $validated[$key];
            }
        }

        return $filters;
    }
}
