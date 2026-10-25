<?php

use App\Http\Controllers\Attachments\AttachmentLinkController;
use Illuminate\Support\Facades\Route;

/*
| Public download of files sent to customers as a secure expiring link
| (App\Support\Attachments\AttachmentDownloadLink). Loaded outside the "web"
| group (no session, cookies or CSRF): only a valid, unexpired signature
| reaches the controller, and downloads are rate limited per address.
*/

Route::get('descargas/{attachment}', AttachmentLinkController::class)
    ->whereNumber('attachment')
    ->middleware(['signed', 'throttle:'.max(1, (int) config('attachments.link_downloads_per_minute', 30)).',1'])
    ->name('attachments.link');
