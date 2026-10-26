<?php

namespace App\Http\Requests\Admin;

use App\Models\DataTransfer;
use App\Policies\HistoryImportPolicy;
use App\Services\Migration\History\HistoryImportBatches;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mappings of the batch: each Whaticket connection to a Chat Manager
 * connection or to a historical connection without credentials, and
 * (optionally) departments, agents and tags to existing records. The
 * records themselves are checked by HistoryImportBatches::updateMappings.
 */
class HistoryImportMappingsRequest extends FormRequest
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
            'connections' => ['nullable', 'array', 'max:500'],
            'connections.*' => ['nullable', 'array'],
            'connections.*.target' => ['required_with:connections.*', 'string', Rule::in([HistoryImportBatches::TARGET_CONNECTION, HistoryImportBatches::TARGET_PLACEHOLDER])],
            'connections.*.connection_id' => ['nullable', 'integer', 'min:1'],
            'departments' => ['nullable', 'array', 'max:500'],
            'departments.*' => ['nullable', 'integer', 'min:1'],
            'agents' => ['nullable', 'array', 'max:500'],
            'agents.*' => ['nullable', 'integer', 'min:1'],
            'tags' => ['nullable', 'array', 'max:500'],
            'tags.*' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{connections?: array<string, mixed>, departments?: array<string, mixed>, agents?: array<string, mixed>, tags?: array<string, mixed>}
     */
    public function mappings(): array
    {
        return array_filter([
            'connections' => $this->input('connections'),
            'departments' => $this->input('departments'),
            'agents' => $this->input('agents'),
            'tags' => $this->input('tags'),
        ], 'is_array');
    }
}
