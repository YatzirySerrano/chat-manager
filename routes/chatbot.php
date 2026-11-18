<?php

use App\Http\Controllers\Chatbot\ChatbotController;
use Illuminate\Support\Facades\Route;

/*
| Chatbot: the welcome menu of each connection and the keyword replies.
| Authorization is enforced by policies in the controller and form requests
| (connections.chatbot); hiding UI elements is never relied upon.
*/

Route::middleware(['auth', 'verified'])->prefix('admin/chatbot')->name('admin.chatbot.')->group(function () {
    Route::get('/', [ChatbotController::class, 'index'])->name('index');
    Route::put('menus/{connection}', [ChatbotController::class, 'updateMenu'])->name('menus.update');
    Route::post('rules', [ChatbotController::class, 'storeRule'])->name('rules.store');
    Route::put('rules-order', [ChatbotController::class, 'reorderRules'])->name('rules.order');
    Route::put('rules/{rule}', [ChatbotController::class, 'updateRule'])->name('rules.update');
    Route::patch('rules/{rule}/active', [ChatbotController::class, 'activateRule'])->name('rules.active');
    Route::delete('rules/{rule}', [ChatbotController::class, 'destroyRule'])->name('rules.destroy');
});
