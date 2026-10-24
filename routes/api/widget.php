<?php

use App\Http\Controllers\WebChat\Api\VisitorAttachmentController;
use App\Http\Controllers\WebChat\Api\VisitorBroadcastingAuthController;
use App\Http\Controllers\WebChat\Api\VisitorIdentityController;
use App\Http\Controllers\WebChat\Api\VisitorMessageController;
use App\Http\Controllers\WebChat\Api\VisitorReceiptController;
use App\Http\Controllers\WebChat\Api\VisitorSessionController;
use Illuminate\Support\Facades\Route;

/*
| Web chat visitor API under /api/v1/widget (names api.v1.widget.*),
| authenticated with the 'webchat.session' middleware (Bearer wvs_…) except
| session creation (frame ticket) and the signed attachment URLs. Called
| only from the widget iframe (same origin): no CORS, session or cookies.
| The authentication middleware always runs before the named throttle.
| Owner: workstream webchat-backend.
*/

Route::prefix('widget')->name('widget.')->group(function (): void {
    Route::post('{publicKey}/sessions', [VisitorSessionController::class, 'store'])
        ->where('publicKey', '[A-Za-z0-9]{32,64}')
        ->middleware('throttle:webchat-sessions')
        ->name('sessions.store');

    Route::post('broadcasting/auth', [VisitorBroadcastingAuthController::class, 'store'])
        ->middleware(['webchat.session', 'throttle:webchat-broadcasting'])
        ->name('broadcasting.auth');

    Route::get('session/attachments/{attachment}', [VisitorAttachmentController::class, 'show'])
        ->whereNumber('attachment')
        ->middleware('signed')
        ->name('session.attachments.show');

    Route::prefix('session')->name('session.')->middleware('webchat.session')->group(function (): void {
        Route::get('/', [VisitorSessionController::class, 'show'])->middleware('throttle:webchat-reads')->name('show');
        Route::delete('/', [VisitorSessionController::class, 'destroy'])->name('destroy');
        Route::post('identity', [VisitorIdentityController::class, 'store'])->middleware('throttle:webchat-sessions')->name('identity.store');
        Route::get('messages', [VisitorMessageController::class, 'index'])->middleware('throttle:webchat-reads')->name('messages.index');
        Route::post('messages', [VisitorMessageController::class, 'store'])->middleware('throttle:webchat-messages')->name('messages.store');
        Route::post('receipts', [VisitorReceiptController::class, 'store'])->middleware('throttle:webchat-reads')->name('receipts.store');
    });
});
