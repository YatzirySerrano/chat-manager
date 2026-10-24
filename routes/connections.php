<?php

use App\Http\Controllers\Admin\ConnectionController;
use App\Http\Controllers\Admin\ConnectionStatusController;
use App\Http\Controllers\Admin\ConnectionVerificationController;
use App\Http\Controllers\Admin\WebChatWidgetController;
use Illuminate\Support\Facades\Route;

/*
| Connections (channels such as WhatsApp numbers): owned by the connections module.
| Authorization is enforced by policies in the controllers and form requests;
| hiding UI elements is never relied upon.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('connections', [ConnectionController::class, 'index'])->name('connections.index');
    Route::get('connections/create', [ConnectionController::class, 'create'])->name('connections.create');
    Route::post('connections', [ConnectionController::class, 'store'])->name('connections.store');
    Route::get('connections/{connection}', [ConnectionController::class, 'show'])->name('connections.show');
    Route::put('connections/{connection}', [ConnectionController::class, 'update'])->name('connections.update');
    Route::delete('connections/{connection}', [ConnectionController::class, 'destroy'])->name('connections.destroy');
    Route::patch('connections/{connection}/restore', [ConnectionController::class, 'restore'])->name('connections.restore');
    Route::patch('connections/{connection}/status', [ConnectionStatusController::class, 'update'])->name('connections.status.update');
    Route::post('connections/{connection}/verify', [ConnectionVerificationController::class, 'store'])->middleware('throttle:10,1')->name('connections.verify');
    Route::put('connections/{connection}/widget', [WebChatWidgetController::class, 'update'])->name('connections.widget.update');
    Route::post('connections/{connection}/widget/rotate-key', [WebChatWidgetController::class, 'rotateKey'])->middleware('throttle:10,1')->name('connections.widget.rotate-key');
    Route::post('connections/{connection}/widget/rotate-identity-secret', [WebChatWidgetController::class, 'rotateIdentitySecret'])->middleware('throttle:10,1')->name('connections.widget.rotate-identity-secret');
});
