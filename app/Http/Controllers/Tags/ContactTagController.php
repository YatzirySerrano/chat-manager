<?php

namespace App\Http\Controllers\Tags;

use App\Actions\Tags\SyncContactTags;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tags\SyncContactTagsRequest;
use App\Models\Contact;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ContactTagController extends Controller
{
    /**
     * Replace the tags of a contact the actor can see.
     */
    public function update(SyncContactTagsRequest $request, Contact $contact, SyncContactTags $syncTags): RedirectResponse
    {
        $changes = $syncTags->handle($contact, $request->tagIds(), $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $changes['added'] === [] && $changes['removed'] === []
                ? 'Las etiquetas no cambiaron.'
                : 'Etiquetas actualizadas.',
        ]);

        return back();
    }
}
