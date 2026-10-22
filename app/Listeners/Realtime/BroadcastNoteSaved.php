<?php

namespace App\Listeners\Realtime;

use App\Broadcasting\RealtimeChannels;
use App\Broadcasting\RealtimeSignals;
use App\Events\Conversations\ConversationNoteSaved;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * An internal note was added or edited: only people looking at the
 * conversation refresh its notes (the text never travels in the signal).
 */
class BroadcastNoteSaved implements ShouldQueue
{
    use QueuesRealtimeSignals;

    public function __construct(private RealtimeSignals $signals) {}

    public function handle(ConversationNoteSaved $event): void
    {
        $this->signals->send([RealtimeChannels::conversation($event->conversationId)], 'note.saved', [
            'note_id' => $event->noteId,
        ]);
    }
}
