<?php

use App\Http\Controllers\Conversations\ConversationAssignmentController;
use App\Http\Controllers\Conversations\ConversationCategoryController;
use App\Http\Controllers\Conversations\ConversationController;
use App\Http\Controllers\Conversations\ConversationMessageController;
use App\Http\Controllers\Conversations\ConversationNoteController;
use App\Http\Controllers\Conversations\ConversationPreviewController;
use App\Http\Controllers\Conversations\ConversationReadController;
use App\Http\Controllers\Conversations\ConversationReadinessController;
use App\Http\Controllers\Conversations\ConversationStatusController;
use App\Http\Controllers\Conversations\ConversationTagController;
use App\Http\Controllers\Conversations\ConversationTimelineController;
use App\Http\Controllers\Conversations\ConversationTransferController;
use App\Http\Controllers\Conversations\MessageAttachmentController;
use Illuminate\Support\Facades\Route;

/*
| Conversation engine: queues, attention, transfers, notes and replies.
| Every operation is authorized by the engine under lock with fresh data;
| hiding UI elements is never relied upon.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('conversations', [ConversationController::class, 'index'])->name('conversations.index');
    Route::get('conversations/page', [ConversationController::class, 'page'])
        ->middleware('throttle:120,1')
        ->name('conversations.page');
    Route::get('conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
    Route::post('conversations/{conversation}/accept', [ConversationAssignmentController::class, 'accept'])->name('conversations.accept');
    Route::post('conversations/{conversation}/assign', [ConversationAssignmentController::class, 'assign'])->name('conversations.assign');
    Route::post('conversations/{conversation}/release', [ConversationAssignmentController::class, 'release'])->name('conversations.release');
    Route::post('conversations/{conversation}/transfer', [ConversationTransferController::class, 'store'])->name('conversations.transfer');
    Route::get('conversations/{conversation}/destinations', [ConversationTransferController::class, 'destinations'])
        ->middleware('throttle:60,1')
        ->name('conversations.destinations');
    Route::post('conversations/{conversation}/close', [ConversationStatusController::class, 'close'])->name('conversations.close');
    Route::post('conversations/{conversation}/reopen', [ConversationStatusController::class, 'reopen'])->name('conversations.reopen');
    Route::post('conversations/{conversation}/notes', [ConversationNoteController::class, 'store'])->name('conversations.notes.store');
    Route::put('conversations/{conversation}/notes/{note}', [ConversationNoteController::class, 'update'])->name('conversations.notes.update');
    Route::post('conversations/{conversation}/messages', [ConversationMessageController::class, 'store'])
        ->middleware('throttle:60,1')
        ->name('conversations.messages.store');
    Route::get('conversations/{conversation}/timeline', [ConversationTimelineController::class, 'index'])
        ->middleware('throttle:120,1')
        ->name('conversations.timeline');
    // Read-only preview from the list: never moves the read marker.
    Route::get('conversations/{conversation}/preview', [ConversationPreviewController::class, 'show'])
        ->middleware('throttle:120,1')
        ->name('conversations.preview');
    Route::post('conversations/{conversation}/read', [ConversationReadController::class, 'store'])
        ->middleware('throttle:120,1')
        ->name('conversations.read');
    Route::put('conversations/{conversation}/tags', [ConversationTagController::class, 'update'])->name('conversations.tags.update');
    Route::post('conversations/{conversation}/readiness', [ConversationReadinessController::class, 'update'])->middleware('phase-two')->name('conversations.readiness.update');
    Route::put('conversations/{conversation}/category', [ConversationCategoryController::class, 'update'])->name('conversations.category.update');
    Route::get('conversations/{conversation}/messages/{message}/attachments/{attachment}', [MessageAttachmentController::class, 'show'])
        ->name('conversations.messages.attachments.show');
});
