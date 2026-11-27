<?php

use App\Http\Controllers\Conversations\BulkConversationPurgeController;
use App\Http\Controllers\Conversations\ConversationAssignmentController;
use App\Http\Controllers\Conversations\ConversationCategoryController;
use App\Http\Controllers\Conversations\ConversationController;
use App\Http\Controllers\Conversations\ConversationMessageController;
use App\Http\Controllers\Conversations\ConversationNoteController;
use App\Http\Controllers\Conversations\ConversationOutcomeController;
use App\Http\Controllers\Conversations\ConversationPermanentDeletionController;
use App\Http\Controllers\Conversations\ConversationPreviewController;
use App\Http\Controllers\Conversations\ConversationReadController;
use App\Http\Controllers\Conversations\ConversationReadinessController;
use App\Http\Controllers\Conversations\ConversationRequestFieldController;
use App\Http\Controllers\Conversations\ConversationStartController;
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
    // «Mensajes web»: the same inbox, only for the web chat.
    Route::get('web-messages', [ConversationController::class, 'webIndex'])->name('web-messages.index');
    Route::get('conversations/page', [ConversationController::class, 'page'])
        ->middleware('throttle:120,1')
        ->name('conversations.page');
    // «Iniciar chat»: the agent writes first through a WhatsApp connection.
    Route::get('conversations/start/options', [ConversationStartController::class, 'options'])
        ->middleware('throttle:60,1')
        ->name('conversations.start.options');
    Route::get('conversations/start/contacts', [ConversationStartController::class, 'contacts'])
        ->middleware('throttle:120,1')
        ->name('conversations.start.contacts');
    Route::post('conversations/start', [ConversationStartController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('conversations.start.store');
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
    // Credit result of a completed request: the suggestion read from the chat, and its correction.
    Route::get('conversations/{conversation}/outcome/suggestion', [ConversationOutcomeController::class, 'suggestion'])
        ->middleware('throttle:120,1')
        ->name('conversations.outcome.suggestion');
    Route::put('conversations/{conversation}/outcome', [ConversationOutcomeController::class, 'update'])
        ->middleware('throttle:60,1')
        ->name('conversations.outcome.update');
    // «Datos de la solicitud»: suggestions read from one message, and the fields kept.
    Route::get('conversations/{conversation}/messages/{message}/request-fields', [ConversationRequestFieldController::class, 'parse'])
        ->middleware('throttle:120,1')
        ->name('conversations.request-fields.parse');
    Route::put('conversations/{conversation}/request-fields', [ConversationRequestFieldController::class, 'update'])
        ->middleware('throttle:60,1')
        ->name('conversations.request-fields.update');
    Route::delete('conversations/{conversation}/permanent', ConversationPermanentDeletionController::class)
        ->middleware('throttle:20,1')
        ->name('conversations.destroy-permanently');
    // «Vaciar conversaciones definitivamente» (Superadministrador): all closed,
    // the whole Whaticket history, or one lote. Called in a loop a chunk at a
    // time, so the throttle is high enough for a large purge to run through.
    Route::post('conversations/purge', BulkConversationPurgeController::class)
        ->middleware('throttle:300,1')
        ->name('conversations.purge');
    Route::get('conversations/{conversation}/messages/{message}/attachments/{attachment}', [MessageAttachmentController::class, 'show'])
        ->name('conversations.messages.attachments.show');
});
