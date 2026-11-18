<?php

use App\Http\Controllers\LinkPreviewController;
use Illuminate\Support\Facades\Route;

/*
| Preview cards of the web addresses written in messages. Each one is
| authorized like the chat it belongs to and reads the address from the
| stored message (never from the request). Fetching a site is bounded per
| viewer; the images are served through signed addresses.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::middleware('throttle:60,1')->group(function () {
        Route::get('conversations/{conversation}/messages/{message}/link-preview', [LinkPreviewController::class, 'message'])
            ->name('conversations.messages.link-preview');
        Route::get('conversations/{conversation}/notes/{note}/link-preview', [LinkPreviewController::class, 'note'])
            ->name('conversations.notes.link-preview');
        Route::get('team-chat/threads/{thread}/messages/{message}/link-preview', [LinkPreviewController::class, 'teamMessage'])
            ->name('team-chat.threads.messages.link-preview');
    });

    Route::get('link-previews/{linkPreview}/{variant}', [LinkPreviewController::class, 'image'])
        ->whereIn('variant', ['image', 'icon'])
        ->middleware(['signed', 'throttle:240,1'])
        ->name('link-previews.image');
});
