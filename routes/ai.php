<?php

use App\Http\Controllers\Ai\AiController;
use Illuminate\Support\Facades\Route;

/*
| «Inteligencia artificial»: the provider and API key, the business
| information and the connections where the assistant answers.
| Authorization is enforced in the controller and form request
| (connections.ai); hiding UI elements is never relied upon.
*/

Route::middleware(['auth', 'verified'])->prefix('admin/ai')->name('admin.ai.')->group(function () {
    Route::get('/', [AiController::class, 'index'])->name('index');
    Route::put('/', [AiController::class, 'update'])->name('update');
    Route::post('test', [AiController::class, 'test'])->middleware('throttle:10,1')->name('test');
    Route::put('connections/{connection}', [AiController::class, 'updateConnection'])->name('connections.update');
});
