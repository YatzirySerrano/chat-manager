<?php

namespace App\Http\Controllers\Contacts;

use App\Actions\Contacts\AddContactNote;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\StoreContactNoteRequest;
use App\Models\Contact;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ContactNoteController extends Controller
{
    /**
     * Add a private note to the contact.
     */
    public function store(StoreContactNoteRequest $request, Contact $contact, AddContactNote $addNote): RedirectResponse
    {
        $addNote->handle($contact, $request->body(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Nota agregada.']);

        return back();
    }
}
