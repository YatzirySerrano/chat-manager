<?php

namespace App\Services\Conversations;

use Carbon\CarbonImmutable;

/**
 * Why a request cannot be taken from its queue right now: one of the three
 * queue rules (QueueRules), with what the interface needs to explain it.
 */
final readonly class QueueBlock
{
    public function __construct(
        /** Stable machine code: needs_info, min_wait or queue_order. */
        public string $code,
        public string $message,
        /** Minimum wait: the exact moment the request becomes available. */
        public ?CarbonImmutable $availableAt = null,
        /** Chronological order: the earlier request that must be taken first. */
        public ?int $blockingConversationId = null,
    ) {}
}
