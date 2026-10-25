<?php

use App\Http\Controllers\Admin\PersonalItemController;
use App\Http\Controllers\Personalization\ChatAppearanceController;
use App\Http\Controllers\Personalization\ContactAliasController;
use Illuminate\Support\Facades\Route;

/*
| Personal items: each user's private contact aliases and chat colors, set
| from a conversation they can see, and their supervision by authorized
| administrators. Authorization lives in the form requests, policies and
| controllers; hiding UI elements is never relied upon.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::put('conversations/{conversation}/alias', [ContactAliasController::class, 'update'])
        ->middleware('throttle:60,1')
        ->name('conversations.alias.update');
    Route::delete('conversations/{conversation}/alias', [ContactAliasController::class, 'destroy'])
        ->middleware('throttle:60,1')
        ->name('conversations.alias.destroy');

    Route::put('conversations/{conversation}/appearance', [ChatAppearanceController::class, 'update'])
        ->middleware('throttle:60,1')
        ->name('conversations.appearance.update');
    Route::delete('conversations/{conversation}/appearance', [ChatAppearanceController::class, 'destroy'])
        ->middleware('throttle:60,1')
        ->name('conversations.appearance.destroy');

    Route::prefix('admin/personal-items')->name('admin.personal-items.')->group(function () {
        Route::get('/', [PersonalItemController::class, 'index'])->name('index');
        Route::put('aliases/{alias}', [PersonalItemController::class, 'updateAlias'])->name('aliases.update');
        Route::delete('aliases/{alias}', [PersonalItemController::class, 'destroyAlias'])->name('aliases.destroy');
        Route::put('appearances/{appearance}', [PersonalItemController::class, 'updateAppearance'])->name('appearances.update');
        Route::delete('appearances/{appearance}', [PersonalItemController::class, 'destroyAppearance'])->name('appearances.destroy');
    });
});
