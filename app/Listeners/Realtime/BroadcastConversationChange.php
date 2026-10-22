<?php

namespace App\Listeners\Realtime;

use App\Broadcasting\RealtimeSignals;
use App\Events\Conversations\ConversationChanged;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Lifecycle changes (created, accepted, transferred, closed...) and the
 * other conversation changes (tags, routing, read markers) become signals
 * for the conversation and for the inboxes that list it, before and after.
 */
class BroadcastConversationChange implements ShouldQueue
{
    use QueuesRealtimeSignals;

    public function __construct(private RealtimeSignals $signals) {}

    public function handle(ConversationChanged $event): void
    {
        $conversation = Conversation::query()->find($event->conversationId);

        if ($conversation === null) {
            return;
        }

        // Read markers belong to one user: only their other tabs refresh.
        if ($event->reason === 'read') {
            $this->signals->toUsers([$event->actorId], 'inbox.changed', [
                'conversation_id' => $conversation->id,
                'reason' => 'read',
            ]);

            return;
        }

        $record = $event->eventId === null ? null : ConversationEvent::query()
            ->whereKey($event->eventId)
            ->where('conversation_id', $conversation->id)
            ->first();

        $this->signals->conversationChanged($conversation, $event->reason, $record);
    }
}
