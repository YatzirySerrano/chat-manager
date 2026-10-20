<?php

use App\Http\Controllers\QuickReplies\AvailableQuickReplyController;
use App\Http\Controllers\QuickReplies\QuickReplyController;
use App\Http\Controllers\QuickReplies\QuickReplyStatusController;
use Illuminate\Support\Facades\Route;

/*
| Quick replies: owned by the quick replies module.
| Authorization is enforced by policies in the controllers and form requests;
| hiding UI elements is never relied upon.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('quick-replies', [QuickReplyController::class, 'index'])->name('quick-replies.index');
    Route::get('quick-replies/available', AvailableQuickReplyController::class)->name('quick-replies.available');
    Route::post('quick-replies', [QuickReplyController::class, 'store'])->name('quick-replies.store');
    Route::put('quick-replies/{quickReply}', [QuickReplyController::class, 'update'])->name('quick-replies.update');
    Route::patch('quick-replies/{quickReply}/status', [QuickReplyStatusController::class, 'update'])->name('quick-replies.status.update');
    Route::delete('quick-replies/{quickReply}', [QuickReplyController::class, 'destroy'])->name('quick-replies.destroy');
});
