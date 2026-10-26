<?php

namespace App\Http\Requests\Admin;

use App\Models\DataTransfer;
use App\Policies\HistoryImportPolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Column mapping chosen by the admin for one file (logical field => column
 * index, or null to leave a field without column).
 */
class HistoryImportColumnMappingRequest extends FormRequest
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
            'mapping' => ['required', 'array', 'max:40'],
            'mapping.*' => ['nullable', 'integer', 'min:0', 'max:200'],
        ];
    }
}
