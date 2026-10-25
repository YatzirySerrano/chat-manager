<?php

use App\Http\Controllers\Contacts\ContactAttributionController;
use App\Http\Controllers\Contacts\ContactController;
use App\Http\Controllers\Contacts\ContactDirectoryController;
use App\Http\Controllers\Contacts\ContactNoteController;
use App\Http\Controllers\Contacts\ContactReviewController;
use App\Http\Controllers\Conversations\ConversationContactController;
use Illuminate\Support\Facades\Route;

/*
| Contacts, identities, origin, private notes and tag assignment: owned by the contacts module.
| Authorization is enforced by policies in the controllers and form requests;
| hiding UI elements is never relied upon.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('contacts', [ContactController::class, 'index'])->name('contacts.index');
    Route::post('contacts', [ContactController::class, 'store'])->name('contacts.store');
    Route::get('contacts/reviews', [ContactReviewController::class, 'index'])->name('contacts.reviews.index');
    Route::patch('contacts/reviews/{contactReviewCase}', [ContactReviewController::class, 'update'])->name('contacts.reviews.update');
    Route::get('contacts/{contact}', [ContactController::class, 'show'])->name('contacts.show');
    Route::put('contacts/{contact}', [ContactController::class, 'update'])->name('contacts.update');
    Route::delete('contacts/{contact}', [ContactController::class, 'destroy'])->name('contacts.destroy');
    Route::patch('contacts/{contact}/restore', [ContactController::class, 'restore'])->name('contacts.restore');
    Route::post('contacts/{contact}/notes', [ContactNoteController::class, 'store'])->name('contacts.notes.store');
    Route::post('contacts/{contact}/attributions', [ContactAttributionController::class, 'store'])->name('contacts.attributions.store');
    // "Guardar contacto": a provisional contact enters the shared directory.
    Route::post('contacts/{contact}/save', [ContactDirectoryController::class, 'store'])->name('contacts.save');
    Route::post('conversations/{conversation}/contact/save', [ConversationContactController::class, 'store'])->name('conversations.contact.save');
});
