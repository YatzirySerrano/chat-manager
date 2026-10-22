<?php

use App\Http\Controllers\Tags\ContactTagController;
use App\Http\Controllers\Tags\TagController;
use Illuminate\Support\Facades\Route;

/*
| Tags: owned by the tags module.
| Authorization is enforced by policies in the controllers and form requests;
| hiding UI elements is never relied upon.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('tags', [TagController::class, 'index'])->name('tags.index');
    Route::post('tags', [TagController::class, 'store'])->name('tags.store');
    Route::put('tags/{tag}', [TagController::class, 'update'])->name('tags.update');
    Route::delete('tags/{tag}', [TagController::class, 'destroy'])->name('tags.destroy');
    Route::patch('tags/{tag}/restore', [TagController::class, 'restore'])->name('tags.restore');

    Route::put('contacts/{contact}/tags', [ContactTagController::class, 'update'])->name('contacts.tags.sync');
});
