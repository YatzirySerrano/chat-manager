<?php

use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\ConversationMessageController;
use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

/*
| Integration API under /api/v1 (names api.v1.*), authenticated with the
| 'api.token' middleware (Bearer cm_live_…) and 'api.ability:{ability}'.
| Authentication always runs before the named throttle, so the limits count
| per token. Conversations are resolved with a query bounded by the token
| (no implicit binding): outside its reach is the same 404 as nonexistent.
| Owner: workstream api-integracion.
*/

Route::middleware('api.token')->group(function (): void {
    Route::get('health', HealthController::class)
        ->middleware('throttle:api-v1-read')
        ->name('health');

    Route::get('conversations', [ConversationController::class, 'index'])
        ->middleware(['api.ability:conversations:read', 'throttle:api-v1-read'])
        ->name('conversations.index');

    Route::get('conversations/{conversation}', [ConversationController::class, 'show'])
        ->whereNumber('conversation')
        ->middleware(['api.ability:conversations:read', 'throttle:api-v1-read'])
        ->name('conversations.show');

    Route::get('conversations/{conversation}/messages', [ConversationMessageController::class, 'index'])
        ->whereNumber('conversation')
        ->middleware(['api.ability:messages:read', 'throttle:api-v1-read'])
        ->name('conversations.messages.index');

    Route::post('conversations/{conversation}/messages', [ConversationMessageController::class, 'store'])
        ->whereNumber('conversation')
        ->middleware(['api.ability:messages:send', 'throttle:api-v1-write'])
        ->name('conversations.messages.store');
});
