<?php

namespace App\Http\Requests\Contacts;

use App\Actions\Contacts\FindDuplicateContacts;
use App\Models\Contact;
use App\Models\Conversation;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * "Guardar contacto": saving a provisional contact into the shared directory,
 * from its conversation or from its contact page. The name and email follow
 * the same rules as editing a contact (ContactRequest); the phone is never
 * taken from the request. An optional private note needs the notes
 * permission.
 */
class SaveContactRequest extends FormRequest
{
    /**
     * A conversation or contact outside the actor's scope answers 404.
     */
    public function authorize(): Response
    {
        $gate = Gate::forUser($this->user());
        $conversation = $this->route('conversation');

        if ($conversation instanceof Conversation && ! $gate->allows('view', $conversation)) {
            return Response::denyAsNotFound();
        }

        return $gate->inspect('saveToDirectory', $this->contact());
    }

    /**
     * The contact being saved: the one of the conversation, or the routed one.
     */
    public function contact(): Contact
    {
        $conversation = $this->route('conversation');

        if ($conversation instanceof Conversation) {
            return $conversation->contact;
        }

        $contact = $this->route('contact');

        return $contact instanceof Contact ? $contact : abort(404);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $canAddNote = Gate::forUser($this->user())->allows('manageNotes', $this->contact());

        return [
            'name' => ['required', 'string', 'max:160'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'note' => $canAddNote
                ? ['nullable', 'string', 'max:'.StoreContactNoteRequest::MAX_LENGTH]
                : ['prohibited'],
            'confirm_duplicate' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The name cannot be blank once cleaned, and a new email used by another
     * contact within the actor's scope must be confirmed first (contacts are
     * never merged).
     *
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if (ContactRequest::clean($this->string('name')->value()) === '') {
                    $validator->errors()->add('name', 'El nombre no puede quedar vacío.');

                    return;
                }

                $email = $this->payload()['email'];

                if ($email === null || $this->boolean('confirm_duplicate') || $email === mb_strtolower((string) $this->contact()->email)) {
                    return;
                }

                $duplicates = app(FindDuplicateContacts::class)->handle(null, $email, $this->user(), $this->contact());

                if ($duplicates !== []) {
                    $validator->errors()->add('email', 'Ya existe otro contacto con este correo electrónico. Si se trata de otra persona, confirma para guardarlo de todos modos.');
                    $validator->errors()->add('confirm_duplicate', 'Confirma si se trata de otra persona.');
                }
            },
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
            'name' => 'nombre',
            'email' => 'correo electrónico',
            'note' => 'nota',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.prohibited' => 'No tienes permiso para agregar notas a este contacto.',
        ];
    }

    /**
     * @return array{name: string, email: string|null, note: string|null}
     */
    public function payload(): array
    {
        $note = $this->filled('note')
            ? trim(preg_replace('/[^\P{Cc}\n\t]+/u', '', str_replace(["\r\n", "\r"], "\n", $this->string('note')->value())) ?? '')
            : '';

        return [
            'name' => ContactRequest::clean($this->string('name')->value()),
            'email' => $this->filled('email') ? mb_strtolower($this->string('email')->trim()->value()) : null,
            'note' => $note === '' ? null : $note,
        ];
    }

    public function confirmsDuplicate(): bool
    {
        return $this->boolean('confirm_duplicate');
    }
}
