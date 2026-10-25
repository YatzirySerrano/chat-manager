<?php

use App\Http\Controllers\Messaging\AttachmentLinkRevocationController;
use App\Http\Controllers\Messaging\ConnectionSubscriptionController;
use App\Http\Controllers\Messaging\ConversationTemplateController;
use App\Http\Controllers\Messaging\MessageRetryController;
use App\Http\Controllers\Messaging\MessageTemplateController;
use App\Http\Controllers\Messaging\MessageTemplateSyncController;
use Illuminate\Support\Facades\Route;

/*
| WhatsApp Cloud API module: templates, message retries and connection
| diagnostics. Authorization lives in the controllers, policies and actions.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('templates', [MessageTemplateController::class, 'index'])->name('templates.index');
    Route::get('templates/{template}', [MessageTemplateController::class, 'show'])->whereNumber('template')->name('templates.show');
    Route::post('templates/sync/{connection}', [MessageTemplateSyncController::class, 'store'])->middleware('throttle:10,1')->name('templates.sync');

    Route::get('conversations/{conversation}/templates', [ConversationTemplateController::class, 'index'])
        ->middleware('throttle:60,1')
        ->name('conversations.templates.index');
    Route::post('conversations/{conversation}/templates', [ConversationTemplateController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('conversations.templates.store');
    Route::post('conversations/{conversation}/messages/{message}/retry', [MessageRetryController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('conversations.messages.retry');
    Route::post('conversations/{conversation}/messages/{message}/attachments/{attachment}/revoke-link', [AttachmentLinkRevocationController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('conversations.messages.attachments.revoke-link');

    Route::post('connections/{connection}/subscription/check', [ConnectionSubscriptionController::class, 'check'])->middleware('throttle:10,1')->name('connections.subscription.check');
    Route::post('connections/{connection}/subscription', [ConnectionSubscriptionController::class, 'store'])->middleware('throttle:10,1')->name('connections.subscription.store');
});
