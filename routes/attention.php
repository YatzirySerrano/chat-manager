<?php

use App\Http\Controllers\Attention\AttentionAlertController;
use App\Http\Controllers\Attention\AttentionController;
use App\Http\Controllers\Attention\MyAttentionAlertController;
use Illuminate\Support\Facades\Route;

/*
| «Seguimiento de atención» (gates view-attention and send-attention-alerts,
| checked by every controller) and the signed-in person's own attention
| alerts (bound to their own rows).
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('attention', [AttentionController::class, 'index'])->name('attention.index');
    Route::post('attention/alerts', [AttentionAlertController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('attention.alerts.store');
    Route::get('attention/alerts/{alert}', [AttentionAlertController::class, 'show'])
        ->whereNumber('alert')
        ->middleware('throttle:60,1')
        ->name('attention.alerts.show');

    Route::get('my-alerts', [MyAttentionAlertController::class, 'index'])
        ->middleware('throttle:120,1')
        ->name('my-alerts.index');
    Route::post('my-alerts/delivered', [MyAttentionAlertController::class, 'delivered'])
        ->middleware('throttle:120,1')
        ->name('my-alerts.delivered');
    Route::post('my-alerts/{recipient}/acknowledge', [MyAttentionAlertController::class, 'acknowledge'])
        ->whereNumber('recipient')
        ->middleware('throttle:60,1')
        ->name('my-alerts.acknowledge');
});
