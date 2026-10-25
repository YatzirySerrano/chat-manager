<?php

namespace App\Http\Controllers\Contacts;

use App\Actions\Contacts\FlagHiddenDuplicates;
use App\Actions\Contacts\SaveContactToDirectory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\SaveContactRequest;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * "Guardar contacto" from the contact page: a provisional contact (an
 * unknown sender registered automatically) enters the shared directory.
 */
class ContactDirectoryController extends Controller
{
    public function store(SaveContactRequest $request, Contact $contact, SaveContactToDirectory $saveContact, FlagHiddenDuplicates $flagHiddenDuplicates): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $payload = $request->payload();

        $contact = $saveContact->handle($contact, $payload, $actor, null, $request->confirmsDuplicate());
        $flagHiddenDuplicates->handle($contact, $actor, null, $payload['email']);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Contacto {$contact->name} guardado en el directorio."]);

        return back();
    }
}
