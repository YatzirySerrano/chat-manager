<?php

use App\Http\Controllers\WebChat\WidgetAgentAvatarController;
use App\Http\Controllers\WebChat\WidgetDemoController;
use App\Http\Controllers\WebChat\WidgetFrameController;
use Illuminate\Support\Facades\Route;

/*
| Public web chat frame (GET /widget/v1/frame/{publicKey}, name widget.frame),
| the pictures of the agents who attend a visitor (signed URLs bound to the
| session, VisitorAgentAvatars; outside /api/v1 so the browser may cache
| them) and, only in local with webchat.demo_enabled, the demo host page.
| Loaded outside the "web" group (no session, cookies or CSRF). The loader itself
| (/widget/v1/chat-manager.js) is a static file built into public/widget/v1.
| Owner: workstream widget.
*/

Route::get('widget/v1/frame/{publicKey}', WidgetFrameController::class)
    ->where('publicKey', '[A-Za-z0-9]{32,64}')
    ->middleware('throttle:webchat-frame')
    ->name('widget.frame');

Route::get('widget/v1/agents/{user}/avatar', [WidgetAgentAvatarController::class, 'show'])
    ->whereNumber('user')
    ->middleware('signed:relative')
    ->name('widget.agents.avatar');

if (app()->environment('local') && config('webchat.demo_enabled')) {
    Route::get('widget/v1/demo/{publicKey}', WidgetDemoController::class)
        ->where('publicKey', '[A-Za-z0-9]{32,64}')
        ->name('widget.demo');
}
