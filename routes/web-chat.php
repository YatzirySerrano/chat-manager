<?php

use App\Http\Controllers\WebChat\WidgetDemoController;
use App\Http\Controllers\WebChat\WidgetFrameController;
use Illuminate\Support\Facades\Route;

/*
| Public web chat frame (GET /widget/v1/frame/{publicKey}, name widget.frame)
| and, only in local with webchat.demo_enabled, the demo host page. Loaded
| outside the "web" group (no session, cookies or CSRF). The loader itself
| (/widget/v1/chat-manager.js) is a static file built into public/widget/v1.
| Owner: workstream widget.
*/

Route::get('widget/v1/frame/{publicKey}', WidgetFrameController::class)
    ->where('publicKey', '[A-Za-z0-9]{32,64}')
    ->middleware('throttle:webchat-frame')
    ->name('widget.frame');

if (app()->environment('local') && config('webchat.demo_enabled')) {
    Route::get('widget/v1/demo/{publicKey}', WidgetDemoController::class)
        ->where('publicKey', '[A-Za-z0-9]{32,64}')
        ->name('widget.demo');
}
