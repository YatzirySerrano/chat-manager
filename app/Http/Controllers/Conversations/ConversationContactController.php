<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Contacts\FlagHiddenDuplicates;
use App\Actions\Contacts\SaveContactToDirectory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\SaveContactRequest;
use App\Models\Conversation;
use App\Models\User;
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
}
