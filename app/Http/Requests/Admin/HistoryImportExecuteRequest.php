<?php

namespace App\Http\Requests\Admin;

use App\Models\DataTransfer;
use App\Policies\HistoryImportPolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Explicit confirmation of a history import: the token of the summary the
 * admin reviewed and the confirmation itself.
 */
class HistoryImportExecuteRequest extends FormRequest
{
    public function authorize(HistoryImportPolicy $policy): Response
    {
        /** @var DataTransfer $batch */
        $batch = $this->route('transfer');

        return $policy->execute($this->user(), $batch);
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'confirmation_token' => ['required', 'string', 'size:64'],
            'confirm' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirmation_token.required' => 'Revisa el resumen antes de confirmar.',
            'confirm.accepted' => 'Confirma que revisaste el resumen de la importación.',
        ];
    }
}
