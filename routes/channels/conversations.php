<?php

use App\Broadcasting\ConversationChannel;
use App\Broadcasting\DepartmentInboxChannel;
use App\Broadcasting\DepartmentQueueChannel;
use App\Broadcasting\GlobalInboxChannel;
use App\Broadcasting\RealtimeChannels;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel(RealtimeChannels::CONVERSATION, ConversationChannel::class);
Broadcast::channel(RealtimeChannels::DEPARTMENT_INBOX, DepartmentInboxChannel::class);
Broadcast::channel(RealtimeChannels::DEPARTMENT_QUEUE, DepartmentQueueChannel::class);
Broadcast::channel(RealtimeChannels::GLOBAL_INBOX, GlobalInboxChannel::class);
