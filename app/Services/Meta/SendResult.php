<?php

namespace App\Services\Meta;

/**
 * Meta's acceptance of an outbound message: the provider message id (wamid).
 * Acceptance is not delivery; sent/delivered/read only come from status webhooks.
 */
final readonly class SendResult
{
    /**
     * @param  string|null  $warning  Spanish note for the agent when only part of the message reached the provider (e.g. the file of a Messenger reply went out but its text did not).
     */
    public function __construct(
        public string $providerMessageId,
        public bool $simulated = false,
        public ?string $warning = null,
    ) {}
}
