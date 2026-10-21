<?php

namespace App\Http\Requests\Admin;

use App\Enums\DistributionMode;
use App\Models\Department;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * How the department distributes its pending conversations. Uses the same
 * authorization as editing the department.
 */
class UpdateDepartmentDistributionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $department = $this->route('department');

        return $department instanceof Department
            && ($this->user()?->can('update', $department) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'distribution_mode' => ['required', Rule::enum(DistributionMode::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'distribution_mode' => 'forma de distribución',
        ];
    }

    public function mode(): DistributionMode
    {
        return $this->enum('distribution_mode', DistributionMode::class) ?? DistributionMode::Shared;
    }
}
