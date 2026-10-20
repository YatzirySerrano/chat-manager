<?php

namespace App\Http\Requests\Contacts;

use App\Enums\ContactReviewResolution;
use App\Models\Contact;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Administrator's decision on a possible duplicate.
 */
class ResolveContactReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('reviewDuplicates', Contact::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'resolution' => ['required', Rule::enum(ContactReviewResolution::class)],
            'target_contact_id' => [
                Rule::requiredIf(fn (): bool => $this->resolution() !== ContactReviewResolution::DistinctPeople),
                'nullable',
                'integer',
            ],
        ];
    }

    public function resolution(): ContactReviewResolution
    {
        return $this->enum('resolution', ContactReviewResolution::class) ?? ContactReviewResolution::DistinctPeople;
    }

    public function targetContactId(): ?int
    {
        return $this->filled('target_contact_id') ? $this->integer('target_contact_id') : null;
    }
}
