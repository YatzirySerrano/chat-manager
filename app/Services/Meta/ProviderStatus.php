<?php

namespace App\Services\Meta;

use Carbon\CarbonImmutable;

/**
 * One entry of the "statuses" array of a WhatsApp Cloud API webhook: Meta's
 * evidence about an outbound message (sent, delivered, read or failed).
 * Pricing and conversation blocks are kept as reported, never interpreted
 * as amounts.
 */
final readonly class ProviderStatus
{
    /**
     * @param  list<array{code: int|null, title: string|null, message: string|null, details: string|null}>  $errors
     * @param  array<array-key, mixed>|null  $conversation
     * @param  array<array-key, mixed>|null  $pricing
     */
    public function __construct(
        public string $phoneNumberId,
        public string $messageId,
        public string $status,
        public CarbonImmutable $occurredAt,
        public ?string $recipientId = null,
        public array $errors = [],
        public ?array $conversation = null,
        public ?array $pricing = null,
        public ?string $callbackData = null,
    ) {}
}
