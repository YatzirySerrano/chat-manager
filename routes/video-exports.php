<?php

use App\Http\Controllers\VideoExports\VideoExportController;
use Illuminate\Support\Facades\Route;

/*
| «Guardar video»: the folder browser over the NAS and the copies of chat
| videos into it (videos.save-to-nas; saving and reading a copy also check
| the access to the chat in the controller).
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('video-exports/folders', [VideoExportController::class, 'folders'])->middleware('throttle:120,1')->name('video-exports.folders.index');
    Route::post('video-exports/folders', [VideoExportController::class, 'storeFolder'])->middleware('throttle:30,1')->name('video-exports.folders.store');
    Route::get('video-exports/{export}', [VideoExportController::class, 'show'])->whereNumber('export')->middleware('throttle:120,1')->name('video-exports.show');
    Route::post('video-exports/{export}/retry', [VideoExportController::class, 'retry'])->whereNumber('export')->middleware('throttle:20,1')->name('video-exports.retry');

    Route::post('conversations/{conversation}/messages/{message}/attachments/{attachment}/save-video', [VideoExportController::class, 'storeForMessage'])
        ->middleware('throttle:30,1')
        ->name('conversations.messages.attachments.save-video');
    Route::post('team-chat/threads/{thread}/messages/{message}/attachments/{attachment}/save-video', [VideoExportController::class, 'storeForTeam'])
        ->middleware('throttle:30,1')
        ->name('team-chat.attachments.save-video');
});
