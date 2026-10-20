<?php

namespace App\Http\Requests\Contacts;

use App\Enums\ContactStatus;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Archive or restore a contact.
 */
class UpdateContactStatusRequest extends FormRequest
{
    /**
     * A contact outside the actor's scope answers 404 (see ContactPolicy).
     */
    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('archive', $this->route('contact'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(ContactStatus::class)],
        ];
    }

    public function status(): ContactStatus
    {
        return $this->enum('status', ContactStatus::class) ?? ContactStatus::Active;
    }
}
