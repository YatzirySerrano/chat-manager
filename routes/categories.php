<?php

use App\Http\Controllers\Categories\ConversationCategoryController;
use App\Http\Controllers\Categories\ConversationCategoryRulesController;
use Illuminate\Support\Facades\Route;

/*
| Request categories: catalog and the service rules each one overrides.
| Authorization is enforced by the policy in the controllers and form
| requests; hiding UI elements is never relied upon.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('categories', [ConversationCategoryController::class, 'index'])->name('categories.index');
    Route::post('categories', [ConversationCategoryController::class, 'store'])->name('categories.store');
    Route::put('categories/{category}', [ConversationCategoryController::class, 'update'])->name('categories.update');
    Route::delete('categories/{category}', [ConversationCategoryController::class, 'destroy'])->name('categories.destroy');
    Route::patch('categories/{category}/restore', [ConversationCategoryController::class, 'restore'])->name('categories.restore');
    Route::put('categories/{category}/rules', [ConversationCategoryRulesController::class, 'update'])->middleware('phase-two')->name('categories.rules.update');
});
