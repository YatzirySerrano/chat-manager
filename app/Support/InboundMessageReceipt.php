<?php

namespace App\Support;

use App\Enums\InboundMessageOutcome;
use App\Models\Conversation;
use App\Models\Message;

/**
 * Result of receiving one customer message.
 */
final readonly class InboundMessageReceipt
{
    public function __construct(
        public InboundContactRegistration $registration,
        public Conversation $conversation,
        public Message $message,
        public InboundMessageOutcome $outcome,
    ) {}
}
