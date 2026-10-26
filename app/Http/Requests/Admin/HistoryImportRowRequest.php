<?php

namespace App\Http\Requests\Admin;

use App\Enums\HistoryImportDecision;
use App\Models\DataTransfer;
use App\Policies\HistoryImportPolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Changes to one conversation of the inventory: its destination (null goes
 * back to undecided), the participants who are the company (Chat.txt and
 * files without direction) and whether its contact is saved to the
 * directory.
 */
class HistoryImportRowRequest extends FormRequest
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
            'decision' => ['sometimes', 'nullable', 'string', Rule::in(HistoryImportDecision::values())],
            'business_participants' => ['sometimes', 'nullable', 'array', 'max:50'],
            'business_participants.*' => ['string', 'max:160'],
            'save_contact' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{decision?: string|null, business_participants?: list<string>|null, save_contact?: bool}
     */
    public function changes(): array
    {
        $changes = [];

        if ($this->exists('decision')) {
            $changes['decision'] = $this->input('decision');
        }

        if ($this->exists('business_participants')) {
            $value = $this->input('business_participants');
            $changes['business_participants'] = is_array($value) ? array_values(array_map('strval', $value)) : null;
        }

        if ($this->exists('save_contact')) {
            $changes['save_contact'] = $this->boolean('save_contact');
        }

        return $changes;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['decision.in' => 'Elige importar, archivar o no importar.'];
    }
}
