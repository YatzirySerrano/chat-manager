<?php

namespace App\Events\Messaging;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The delivery status of a stored message moved forward (sending, accepted,
 * sent, delivered, read) or it failed. Dispatched after commit.
 */
class MessageStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** When the event was raised (Unix time with microseconds), to measure the real-time fan-out. */
    public readonly float $recordedAt;

    public function __construct(
        public readonly int $messageId,
        public readonly int $conversationId,
        public readonly string $status,
        public readonly ?string $previousStatus = null,
        ?float $recordedAt = null,
    ) {
        $this->recordedAt = $recordedAt ?? microtime(true);
    }
}
