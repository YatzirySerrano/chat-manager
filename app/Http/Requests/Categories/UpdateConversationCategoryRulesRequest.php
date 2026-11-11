<?php

namespace App\Http\Requests\Categories;

use App\Models\ConversationCategory;
use App\Services\ServiceRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The service rules a category overrides, sent as `rules` grouped like the
 * settings (`rules.sla.first_response_minutes`, …). Null or an absent key
 * inherits; a rule that a category may not override fails validation. Uses
 * the same authorization as editing the category.
 */
class UpdateConversationCategoryRulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $category = $this->route('category');

        return $category instanceof ConversationCategory
            && ($this->user()?->can('update', $category) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return app(ServiceRules::class)->validationRules(ServiceRules::LEVEL_CATEGORY, 'rules.');
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return app(ServiceRules::class)->validationAttributes(ServiceRules::LEVEL_CATEGORY, 'rules.');
    }

    /**
     * Flat map of dotted rule key → value with the rules that carry a value.
     *
     * @return array<string, int|bool>
     */
    public function overrides(): array
    {
        $rules = $this->input('rules');

        return app(ServiceRules::class)->overridesFrom(ServiceRules::LEVEL_CATEGORY, is_array($rules) ? $rules : []);
    }
}
