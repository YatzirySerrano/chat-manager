<?php

namespace App\Http\Controllers\Contacts;

use App\Actions\Contacts\ChangeContactStatus;
use App\Enums\ContactStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\UpdateContactStatusRequest;
use App\Models\Contact;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ContactStatusController extends Controller
{
    /**
     * Archive or restore a contact.
     */
    public function update(UpdateContactStatusRequest $request, Contact $contact, ChangeContactStatus $changeStatus): RedirectResponse
    {
        $contact = $changeStatus->handle($contact, $request->status(), $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $contact->status === ContactStatus::Archived ? 'Contacto archivado.' : 'Contacto restaurado.',
        ]);

        return back();
    }
}
