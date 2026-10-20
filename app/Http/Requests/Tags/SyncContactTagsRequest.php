<?php

namespace App\Http\Requests\Tags;

use App\Models\Contact;
use App\Models\Tag;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The full list of tags the contact must carry after the request.
 */
class SyncContactTagsRequest extends FormRequest
{
    /**
     * A contact outside the actor's scope is reported as not found (contact
     * policy); a visible one requires tags.assign.
     */
    public function authorize(): bool
    {
        /** @var Contact $contact */
        $contact = $this->route('contact');

        Gate::authorize('view', $contact);

        return $this->user()?->can('assign', [Tag::class, $contact]) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tag_ids' => ['present', 'array', 'max:50'],
            'tag_ids.*' => ['integer', 'distinct', Rule::exists(Tag::class, 'id')],
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tag_ids' => 'etiquetas',
            'tag_ids.*' => 'etiqueta',
        ];
    }

    /**
     * @return list<int>
     */
    public function tagIds(): array
    {
        return array_values(array_map('intval', (array) $this->input('tag_ids', [])));
    }
}
