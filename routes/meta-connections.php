<?php

use App\Http\Controllers\Admin\MetaAuthorizationController;
use Illuminate\Support\Facades\Route;

/*
| Facebook Messenger and Instagram connections authorized with Meta (OAuth).
| Every action requires connections.manage with the organization-wide scope
| (ConnectionPolicy); the callback validates a single-use state.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('connections/meta/{channel}', [MetaAuthorizationController::class, 'show'])
        ->whereIn('channel', ['messenger', 'instagram'])->name('connections.meta.show');
    Route::post('connections/meta/{channel}/authorize', [MetaAuthorizationController::class, 'start'])
        ->whereIn('channel', ['messenger', 'instagram'])->middleware('throttle:10,1')->name('connections.meta.authorize');
    Route::get('connections/meta/{channel}/callback', [MetaAuthorizationController::class, 'callback'])
        ->whereIn('channel', ['messenger', 'instagram'])->middleware('throttle:20,1')->name('connections.meta.callback');
    Route::get('connections/meta/{channel}/accounts', [MetaAuthorizationController::class, 'accounts'])
        ->whereIn('channel', ['messenger', 'instagram'])->name('connections.meta.accounts');
    Route::post('connections/meta/{channel}/accounts', [MetaAuthorizationController::class, 'connect'])
        ->whereIn('channel', ['messenger', 'instagram'])->middleware('throttle:10,1')->name('connections.meta.connect');
    Route::post('connections/{connection}/meta/disconnect', [MetaAuthorizationController::class, 'disconnect'])
        ->middleware('throttle:10,1')->name('connections.meta.disconnect');
});
