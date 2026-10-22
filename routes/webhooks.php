<?php

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
