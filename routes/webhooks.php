<?php

use App\Http\Controllers\Webhooks\MetaDataCallbackController;
use App\Http\Controllers\Webhooks\MetaMediaController;
use App\Http\Controllers\Webhooks\MetaWebhookController;
use App\Http\Controllers\Webhooks\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Public provider webhooks (owned by the WhatsApp Cloud API module). They are
| loaded from web.php without the "web" middleware group: no session, cookies
| or CSRF token apply; every request is authenticated by Meta's signature
| (POST) or verify token (GET), and rate limited per webhook URL.
*/

Route::middleware('throttle:whatsapp-webhooks')->group(function () {
    Route::get('webhooks/whatsapp/{webhookKey}', [WhatsAppWebhookController::class, 'verify'])
        ->where('webhookKey', '[A-Za-z0-9]{32,64}')
        ->name('webhooks.whatsapp.verify');
    Route::post('webhooks/whatsapp/{webhookKey}', [WhatsAppWebhookController::class, 'receive'])
        ->where('webhookKey', '[A-Za-z0-9]{32,64}')
        ->name('webhooks.whatsapp.receive');
});

/*
| App-level webhook and callbacks of the Meta app used by Facebook Messenger
| and Instagram (docs/meta-messenger-instagram.md): signed by Meta with the
| app secret; the media route only answers URLs signed by this server.
| The fixed, public URL is rate limited per source address (meta-webhooks,
| generous) and invalid signatures are additionally counted per address in
| the controller, so unsigned traffic never uses up the quota of Meta's
| real deliveries; the GET verification has its own small limit.
*/
Route::get('webhooks/meta', [MetaWebhookController::class, 'verify'])
    ->middleware('throttle:meta-webhook-verify')
    ->name('webhooks.meta.verify');
Route::post('webhooks/meta', [MetaWebhookController::class, 'receive'])
    ->middleware('throttle:meta-webhooks')
    ->name('webhooks.meta.receive');

Route::middleware('throttle:30,1')->group(function () {
    Route::post('meta/deauthorize', [MetaDataCallbackController::class, 'deauthorize'])->name('meta.deauthorize');
    Route::post('meta/data-deletion', [MetaDataCallbackController::class, 'deletion'])->name('meta.data-deletion');
    Route::get('meta/data-deletion/{code}', [MetaDataCallbackController::class, 'status'])
        ->where('code', '[a-z0-9]{20}')->name('meta.data-deletion.status');
});

Route::get('meta/media/{attachment}', MetaMediaController::class)
    ->whereNumber('attachment')
    ->middleware(['signed', 'throttle:meta-media'])
    ->name('meta.media');
