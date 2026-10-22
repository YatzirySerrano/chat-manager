<?php

namespace App\Events\Conversations;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something about a conversation changed: it was created, accepted,
 * assigned, transferred, put on hold, closed, reopened... It is dispatched
 * once the transaction that recorded the change commits.
 *
 * It only carries identifiers: listeners (real-time signals, notifications,
 * metrics) read the current state from the database, which stays the single
 * source of truth.
 */
class ConversationChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  string  $reason  A ConversationEventType value, or "message" / "read" / "tags".
     */
    public function __construct(
        public readonly int $conversationId,
        public readonly string $reason,
        public readonly ?int $actorId = null,
        public readonly ?int $eventId = null,
    ) {}
}
