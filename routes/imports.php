<?php

use App\Http\Controllers\Contacts\ContactImportController;
use Illuminate\Support\Facades\Route;

/*
| Contacts importer (Excel/CSV), reserved to the Superadministrador through
| contacts.import. Registered before the contacts routes so "contacts/import"
| is never taken for a contact identifier.
*/

Route::middleware(['auth', 'verified'])->prefix('contacts/import')->name('contacts.import.')->group(function () {
    Route::get('/', [ContactImportController::class, 'create'])->name('create');
    Route::get('template', [ContactImportController::class, 'template'])->name('template');
    Route::post('/', [ContactImportController::class, 'store'])->middleware('throttle:10,1')->name('store');
    Route::get('{transfer}', [ContactImportController::class, 'show'])->whereNumber('transfer')->name('show');
    Route::put('{transfer}', [ContactImportController::class, 'update'])->whereNumber('transfer')->name('update');
    Route::post('{transfer}/confirm', [ContactImportController::class, 'confirm'])->whereNumber('transfer')->middleware('throttle:10,1')->name('confirm');
    Route::delete('{transfer}', [ContactImportController::class, 'destroy'])->whereNumber('transfer')->name('destroy');
    Route::get('{transfer}/report', [ContactImportController::class, 'report'])->whereNumber('transfer')->name('report');
});
