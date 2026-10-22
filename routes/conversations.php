<?php

use App\Http\Controllers\Conversations\AgentAvailabilityController;
use App\Http\Controllers\Conversations\ConversationAssignmentController;
use App\Http\Controllers\Conversations\ConversationController;
use App\Http\Controllers\Conversations\ConversationMessageController;
use App\Http\Controllers\Conversations\ConversationNoteController;
use App\Http\Controllers\Conversations\ConversationReadController;
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
    Route::put('conversations/availability', [AgentAvailabilityController::class, 'update'])->name('conversations.availability.update');
    Route::get('conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
    Route::post('conversations/{conversation}/accept', [ConversationAssignmentController::class, 'accept'])->name('conversations.accept');
    Route::post('conversations/{conversation}/assign', [ConversationAssignmentController::class, 'assign'])->name('conversations.assign');
    Route::post('conversations/{conversation}/release', [ConversationAssignmentController::class, 'release'])->name('conversations.release');
    Route::post('conversations/{conversation}/transfer', [ConversationTransferController::class, 'store'])->name('conversations.transfer');
    Route::post('conversations/{conversation}/hold', [ConversationStatusController::class, 'hold'])->name('conversations.hold');
    Route::post('conversations/{conversation}/resume', [ConversationStatusController::class, 'resume'])->name('conversations.resume');
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
    Route::post('conversations/{conversation}/read', [ConversationReadController::class, 'store'])
        ->middleware('throttle:120,1')
        ->name('conversations.read');
    Route::put('conversations/{conversation}/tags', [ConversationTagController::class, 'update'])->name('conversations.tags.update');
    Route::get('conversations/{conversation}/messages/{message}/attachments/{attachment}', [MessageAttachmentController::class, 'show'])
        ->name('conversations.messages.attachments.show');
});
