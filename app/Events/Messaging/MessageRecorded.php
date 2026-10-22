<?php

namespace App\Events\Messaging;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A customer message was stored: an inbound one from WhatsApp, or an
 * outbound one queued by an agent or by an automatic reply. Dispatched after
 * commit; duplicates of an already stored provider message never dispatch it.
 */
class MessageRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $messageId,
        public readonly int $conversationId,
        public readonly string $direction,
    ) {}
}
