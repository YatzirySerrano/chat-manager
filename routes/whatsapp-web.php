<?php

use App\Http\Controllers\Internal\WhatsAppWebEventController;
use App\Http\Controllers\Internal\WhatsAppWebMediaController;
use Illuminate\Support\Facades\Route;

/*
| Internal endpoints of the WhatsApp Web (QR) modality, used only by the
| private Node service (services/whatsapp-web, docs/whatsapp-web-qr-contrato.md).
| They are loaded from web.php without the "web" middleware group: no
| session, cookies or CSRF token apply. Every request is authenticated by the
| shared HMAC signature (timestamp, single-use nonce, body hash), accepted
| only from the service's address (loopback by default) and rate limited.
| The routes a signed-in administrator uses are in connections.php.
*/

Route::post('internal/whatsapp-web/events', WhatsAppWebEventController::class)
    ->middleware('throttle:whatsapp-web-events')
    ->name('internal.whatsapp-web.events');

Route::get('internal/whatsapp-web/media/{attachment}', WhatsAppWebMediaController::class)
    ->whereNumber('attachment')
    ->middleware('throttle:whatsapp-web-media')
    ->name('internal.whatsapp-web.media');
