<?php

namespace App\Http\Requests\Contacts;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Private plain-text note about a contact.
 */
class StoreContactNoteRequest extends FormRequest
{
    public const MAX_LENGTH = 2000;

    /**
     * A contact outside the actor's scope answers 404 (see ContactPolicy).
     */
    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('manageNotes', $this->route('contact'));
    }

    /**
     * Strip control characters before validating, so a body made only of
     * them is rejected as empty instead of being stored blank.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('body'))) {
            $this->merge(['body' => $this->body()]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.self::MAX_LENGTH],
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
            'body' => 'nota',
        ];
    }

    /**
     * The note as plain text: line breaks are kept, other control characters
     * are removed and it is stored exactly as typed otherwise (it is always
     * rendered escaped).
     */
    public function body(): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $this->string('body')->value());

        return trim(preg_replace('/[^\P{Cc}\n\t]+/u', '', $body) ?? '');
    }
}
