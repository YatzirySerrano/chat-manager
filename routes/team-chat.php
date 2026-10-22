<?php

use App\Http\Controllers\TeamChat\TeamAttachmentController;
use App\Http\Controllers\TeamChat\TeamChatController;
use App\Http\Controllers\TeamChat\TeamChatPeopleController;
use App\Http\Controllers\TeamChat\TeamChatSearchController;
use App\Http\Controllers\TeamChat\TeamMessageController;
use App\Http\Controllers\TeamChat\TeamParticipantController;
use App\Http\Controllers\TeamChat\TeamThreadController;
use Illuminate\Support\Facades\Route;

/*
| Internal chat between employees, independent from customer conversations.
| Every route authorizes the viewer as a current participant of the thread
| (404 otherwise, also for administrators); hiding UI elements is never
| relied upon.
*/

Route::middleware(['auth', 'verified'])->prefix('team-chat')->name('team-chat.')->group(function () {
    Route::get('/', [TeamChatController::class, 'index'])->name('index');
    Route::get('people', TeamChatPeopleController::class)->name('people');
    Route::get('search', TeamChatSearchController::class)->middleware('throttle:60,1')->name('search');
    Route::post('direct', [TeamThreadController::class, 'storeDirect'])->name('direct.store');
    Route::post('groups', [TeamThreadController::class, 'storeGroup'])->name('groups.store');

    Route::prefix('threads/{thread}')->name('threads.')->scopeBindings()->group(function () {
        Route::get('/', [TeamChatController::class, 'show'])->name('show');
        Route::put('/', [TeamThreadController::class, 'update'])->name('update');
        Route::post('archive', [TeamThreadController::class, 'archive'])->name('archive');
        Route::post('unarchive', [TeamThreadController::class, 'unarchive'])->name('unarchive');
        Route::post('leave', [TeamThreadController::class, 'leave'])->name('leave');
        Route::put('mute', [TeamThreadController::class, 'mute'])->name('mute');
        Route::post('read', [TeamThreadController::class, 'read'])->name('read');

        Route::post('participants', [TeamParticipantController::class, 'store'])->name('participants.store');
        Route::put('participants/{user}', [TeamParticipantController::class, 'update'])->withoutScopedBindings()->name('participants.update');
        Route::delete('participants/{user}', [TeamParticipantController::class, 'destroy'])->withoutScopedBindings()->name('participants.destroy');

        Route::get('messages', [TeamMessageController::class, 'index'])->name('messages.index');
        Route::post('messages', [TeamMessageController::class, 'store'])->middleware('throttle:120,1')->name('messages.store');
        Route::put('messages/{message}', [TeamMessageController::class, 'update'])->name('messages.update');
        Route::delete('messages/{message}', [TeamMessageController::class, 'destroy'])->name('messages.destroy');
    });

    Route::get('threads/{thread}/messages/{message}/attachments/{attachment}', [TeamAttachmentController::class, 'show'])
        ->scopeBindings()
        ->name('attachments.show');
});
