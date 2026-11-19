<?php

namespace App\Services\Ai;

/**
 * What the assistant sends to a provider: the stable instructions (business
 * information and catalog, cacheable), the context that changes on every
 * request (date, customer name, automatic messages already sent) and the
 * recent conversation as alternating user / assistant turns, ending with
 * the customer.
 */
final readonly class AiRequest
{
    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     */
    public function __construct(
        public string $apiKey,
        public string $model,
        public string $instructions,
        public string $context,
        public array $messages,
        public int $maxTokens = 4096,
    ) {}
}
