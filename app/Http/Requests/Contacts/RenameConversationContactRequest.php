<?php

namespace App\Http\Requests\Contacts;

use App\Models\Contact;
use App\Models\Conversation;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * "Editar nombre" from the inbox details panel: changes the official name of
 * the conversation's contact, the one everybody sees (unlike the private
 * alias). Needs the conversation within reach and permission to edit the
 * contact; a conversation the actor cannot see answers 404.
 */
class RenameConversationContactRequest extends FormRequest
{
    public function authorize(): Response
    {
        $gate = Gate::forUser($this->user());
        $conversation = $this->route('conversation');

        if (! $conversation instanceof Conversation || ! $gate->allows('view', $conversation)) {
            return Response::denyAsNotFound();
        }

        return $gate->inspect('update', $this->contact());
    }

    public function contact(): Contact
    {
        $conversation = $this->route('conversation');

        return $conversation instanceof Conversation && $conversation->contact !== null ? $conversation->contact : abort(404);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isEmpty() && $this->name() === '') {
                    $validator->errors()->add('name', 'El nombre no puede quedar vacío.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'nombre'];
    }

    public function name(): string
    {
        return ContactRequest::clean($this->string('name')->value());
    }
}
