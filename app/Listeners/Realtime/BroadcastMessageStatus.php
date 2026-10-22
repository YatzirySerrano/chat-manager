<?php

namespace App\Listeners\Realtime;

use App\Broadcasting\RealtimeChannels;
use App\Broadcasting\RealtimeSignals;
use App\Events\Messaging\MessageStatusChanged;
use App\Models\Conversation;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Delivery progress of a message (accepted, sent, delivered, read, failed)
 * reaches whoever looks at the conversation; a failure also reaches the
 * responsible agent wherever they are, so it never goes unnoticed.
 */
class BroadcastMessageStatus implements ShouldQueue
{
    use QueuesRealtimeSignals;

    public function __construct(private RealtimeSignals $signals) {}

    public function handle(MessageStatusChanged $event): void
    {
        $conversation = Conversation::query()->find($event->conversationId);

        if ($conversation === null) {
            return;
        }

        $this->signals->send([RealtimeChannels::conversation($conversation->id)], 'message.status', [
            'message_id' => $event->messageId,
            'status' => $event->status,
        ]);

        if ($event->status === 'failed') {
            $this->signals->toUsers([$conversation->assigned_user_id], 'inbox.changed', [
                'conversation_id' => $conversation->id,
                'reason' => 'message_failed',
            ]);
        }
    }
}
