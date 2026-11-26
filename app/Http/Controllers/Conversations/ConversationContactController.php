<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Contacts\FlagHiddenDuplicates;
use App\Actions\Contacts\SaveContactToDirectory;
use App\Actions\Contacts\UpdateContact;
use App\Events\Conversations\ConversationChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\RenameConversationContactRequest;
use App\Http\Requests\Contacts\SaveContactRequest;
use App\Models\Conversation;
use App\Models\User;
use App\Support\SaveToast;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * "Guardar contacto" from the inbox details panel: the customer of the
 * conversation, registered automatically as a provisional contact, enters the
 * shared directory. The conversation, its identity and replies are not
 * affected. The request answers 404 for conversations the actor cannot see.
 */
class ConversationContactController extends Controller
{
    public function store(SaveContactRequest $request, Conversation $conversation, SaveContactToDirectory $saveContact, FlagHiddenDuplicates $flagHiddenDuplicates): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $payload = $request->payload();

        $contact = $saveContact->handle($request->contact(), $payload, $actor, $conversation, $request->confirmsDuplicate());
        $flagHiddenDuplicates->handle($contact, $actor, null, $payload['email']);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Contacto {$contact->name} guardado en el directorio."]);

        return back();
    }

    /**
     * "Editar nombre": the official name of the contact, for everybody and
     * in every conversation of the person. Phone, email, department and
     * custom fields are kept as they are.
     */
    public function rename(RenameConversationContactRequest $request, Conversation $conversation, UpdateContact $updateContact): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $contact = $request->contact();

        $changed = $updateContact->handle($contact, [
            'name' => $request->name(),
            'phone_e164' => $contact->phone_e164,
            'email' => $contact->email,
            'custom_fields' => $contact->custom_fields,
        ], $actor);

        if ($changed) {
            $contact->conversations()
                ->notClosed()
                ->pluck('id')
                ->each(fn (mixed $id) => ConversationChanged::dispatch((int) $id, 'contact', $actor->id));
        }

        SaveToast::flash($changed, 'Nombre del contacto actualizado para todo el equipo.');

        return back();
    }
}
