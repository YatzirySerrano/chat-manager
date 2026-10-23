<?php

use App\Broadcasting\TeamChat\TeamDepartmentPresenceChannel;
use App\Broadcasting\TeamChat\TeamPresenceChannel;
use App\Broadcasting\TeamChat\TeamThreadChannel;
use Illuminate\Support\Facades\Broadcast;

/*
| Internal chat channels: a thread channel for its current participants and
| the presence channels for online indicators ("team" for people who reach
| everyone, one per department for its members).
*/

Broadcast::channel(TeamThreadChannel::NAME, TeamThreadChannel::class);
Broadcast::channel(TeamPresenceChannel::NAME, TeamPresenceChannel::class);
Broadcast::channel(TeamPresenceChannel::DEPARTMENT, TeamDepartmentPresenceChannel::class);
