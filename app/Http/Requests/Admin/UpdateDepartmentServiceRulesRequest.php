<?php

namespace App\Http\Requests\Admin;

use App\Models\Department;
use App\Services\ServiceRules;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The service rules a department overrides, sent as `rules` grouped like
 * the settings (`rules.sla.first_response_minutes`, …). Null or an absent
 * key inherits the platform value; a rule that a department may not
 * override fails validation. Uses the same authorization as editing the
 * department.
 */
class UpdateDepartmentServiceRulesRequest extends FormRequest
{
    public function authorize(): Response
    {
        $department = $this->route('department');

        return $department instanceof Department ? Gate::inspect('update', $department) : Response::deny();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return app(ServiceRules::class)->validationRules(ServiceRules::LEVEL_DEPARTMENT, 'rules.');
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return app(ServiceRules::class)->validationAttributes(ServiceRules::LEVEL_DEPARTMENT, 'rules.');
    }

    /**
     * Flat map of dotted rule key → value with the rules that carry a value.
     *
     * @return array<string, int|bool>
     */
    public function overrides(): array
    {
        $rules = $this->input('rules');

        return app(ServiceRules::class)->overridesFrom(ServiceRules::LEVEL_DEPARTMENT, is_array($rules) ? $rules : []);
    }
}
