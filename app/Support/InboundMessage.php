<?php

namespace App\Support;

use App\Enums\MessageType;

/**
 * A customer message read from a provider webhook: who sent it (with the
 * provider's message id, timestamp and referral) and what it contains.
 * Media is described by the provider's ids; downloading it is not part of
 * receiving the message.
 */
final readonly class InboundMessage
{
    /**
     * @param  array<array-key, mixed>|null  $content  Provider payload of the message type (text, image, ...), as received.
     * @param  array<array-key, mixed>|null  $context  Provider context, e.g. the message being replied to.
     */
    public function __construct(
        public string $phoneNumberId,
        public InboundSender $sender,
        public MessageType $type,
        public ?string $body,
        public ?array $content = null,
        public ?array $context = null,
    ) {}
}
