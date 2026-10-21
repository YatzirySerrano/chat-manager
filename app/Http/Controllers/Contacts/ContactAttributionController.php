<?php

namespace App\Http\Controllers\Contacts;

use App\Actions\Contacts\ClassifyContactOrigin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\ClassifyContactOriginRequest;
use App\Models\Contact;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ContactAttributionController extends Controller
{
    /**
     * Manually declare how the contact reached the company.
     */
    public function store(ClassifyContactOriginRequest $request, Contact $contact, ClassifyContactOrigin $classifyOrigin): RedirectResponse
    {
        $classifyOrigin->handle($contact, $request->payload(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Procedencia declarada registrada.']);

        return back();
    }
}
