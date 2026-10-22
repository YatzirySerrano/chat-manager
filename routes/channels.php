<?php

use App\Broadcasting\RealtimeChannels;
use App\Broadcasting\UserChannel;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Real-time channels
|--------------------------------------------------------------------------
|
| Every channel is private and authorized again on each subscription
| (POST /broadcasting/auth, behind the web session, "auth" and "verified").
| Modules register their own channels in routes/channels/*.php; see
| docs/tiempo-real.md for the catalogue of channels and events.
|
*/

Broadcast::channel(RealtimeChannels::USER, UserChannel::class);

foreach (glob(__DIR__.'/channels/*.php') ?: [] as $channels) {
    require $channels;
}
