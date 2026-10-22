<?php

namespace App\Services\Meta;

/**
 * Meta's acceptance of an outbound message: the provider message id (wamid).
 * Acceptance is not delivery; sent/delivered/read only come from status webhooks.
 */
final readonly class SendResult
{
    public function __construct(
        public string $providerMessageId,
        public bool $simulated = false,
    ) {}
}
