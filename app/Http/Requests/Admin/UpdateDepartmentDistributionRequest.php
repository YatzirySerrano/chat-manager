<?php

namespace App\Http\Requests\Admin;

use App\Enums\DistributionMode;
use App\Models\Department;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * How the department distributes its pending conversations, and optionally
 * the per agent load limit of its conversations. Uses the same authorization
 * as editing the department.
 */
class UpdateDepartmentDistributionRequest extends FormRequest
{
    public function authorize(): Response
    {
        $department = $this->route('department');

        return $department instanceof Department ? Gate::inspect('update', $department) : Response::deny();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'distribution_mode' => ['required', Rule::enum(DistributionMode::class)],
            'max_open_conversations_per_agent' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'distribution_mode' => 'forma de distribución',
            'max_open_conversations_per_agent' => 'límite de conversaciones por agente',
        ];
    }

    public function mode(): DistributionMode
    {
        return $this->enum('distribution_mode', DistributionMode::class) ?? DistributionMode::Shared;
    }

    /**
     * The per agent limit when the form sent it (empty means "inherit the
     * platform limit"); an omitted key keeps the current one.
     *
     * @return array{max_open_conversations_per_agent?: int|null}
     */
    public function capacity(): array
    {
        if (! $this->has('max_open_conversations_per_agent')) {
            return [];
        }

        return ['max_open_conversations_per_agent' => $this->filled('max_open_conversations_per_agent') ? $this->integer('max_open_conversations_per_agent') : null];
    }
}
