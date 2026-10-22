<?php

namespace App\Listeners\Realtime;

use App\Broadcasting\RealtimeChannels;
use App\Broadcasting\RealtimeSignals;
use App\Events\Messaging\MessageRecorded;
use App\Models\Conversation;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A new inbound or outbound message: people looking at the conversation
 * fetch it, and the inboxes listing the conversation refresh their order,
 * preview and counters. The signal never includes the message itself.
 */
class BroadcastMessageRecorded implements ShouldQueue
{
    use QueuesRealtimeSignals;

    public function __construct(private RealtimeSignals $signals) {}

    public function handle(MessageRecorded $event): void
    {
        $conversation = Conversation::query()->find($event->conversationId);

        if ($conversation === null) {
            return;
        }

        $this->signals->send([RealtimeChannels::conversation($conversation->id)], 'message.recorded', [
            'message_id' => $event->messageId,
            'direction' => $event->direction,
        ]);

        $this->signals->inboxChanged($conversation, 'message');
    }
}
