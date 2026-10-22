<?php

use App\Broadcasting\TeamChat\TeamPresenceChannel;
use App\Broadcasting\TeamChat\TeamThreadChannel;
use Illuminate\Support\Facades\Broadcast;

/*
| Internal chat channels: a thread channel for its current participants and
| the "team" presence channel for online indicators.
*/

Broadcast::channel(TeamThreadChannel::NAME, TeamThreadChannel::class);
Broadcast::channel(TeamPresenceChannel::NAME, TeamPresenceChannel::class);
