<?php

namespace App\Http\Requests\Imports;

use App\Models\DataTransfer;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Explicit confirmation of the logical rollback of an import batch, after
 * its preview. Only the owner of the import, while holding contacts.import.
 */
class RollbackContactImportRequest extends FormRequest
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
        return [
            'confirmation' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirmation.accepted' => 'Confirma que revisaste la vista previa de la reversión.',
        ];
    }
}
