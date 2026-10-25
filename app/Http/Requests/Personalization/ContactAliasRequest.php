<?php

namespace App\Http\Requests\Personalization;

use App\Actions\Personalization\SetContactAlias;
use App\Models\ContactAlias;
use App\Models\Conversation;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * A private alias for a contact: plain text up to 80 characters, trimmed,
 * without control characters. Empty removes the alias. Who may set it is
 * authorized by the controller (own: the conversation must be visible;
 * someone else's: personal items supervision).
 */
class ContactAliasRequest extends FormRequest
{
    /**
     * From a conversation the viewer must see it (otherwise 404, like a
     * missing one); someone else's item goes through its policy
     * (personal items supervision). Both run before any validation.
     */
    public function authorize(): Response
    {
        $conversation = $this->route('conversation');

        if ($conversation instanceof Conversation) {
            return Gate::forUser($this->user())->allows('view', $conversation) ? Response::allow() : Response::denyAsNotFound();
        }

        $item = $this->route('alias');

        return $item instanceof ContactAlias ? Gate::forUser($this->user())->inspect('update', $item) : Response::denyAsNotFound();
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('alias'))) {
            $this->merge(['alias' => trim(preg_replace('/[\p{C}]/u', '', $this->input('alias')) ?? '')]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'alias' => ['present', 'nullable', 'string', 'max:'.SetContactAlias::MAX_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['alias' => 'alias'];
    }

    public function alias(): ?string
    {
        $alias = $this->validated('alias');

        return is_string($alias) && $alias !== '' ? $alias : null;
    }
}
