<?php

namespace App\Support;

use App\Enums\CustomerWindowStatus;
use Carbon\CarbonImmutable;

/**
 * Snapshot of WhatsApp's customer service window at a given moment (UTC).
 */
final readonly class CustomerWindowState
{
    public function __construct(
        public CustomerWindowStatus $status,
        public ?CarbonImmutable $lastInboundAt,
        public ?CarbonImmutable $expiresAt,
        public int $remainingSeconds,
        public ?string $blockReason,
    ) {}

    /**
     * Whether an ordinary (non template) message may be sent right now.
     */
    public function allowsFreeFormMessages(): bool
    {
        return $this->status->allowsFreeFormMessages();
    }

    /**
     * @return array{status: string, label: string, last_inbound_at: string|null, expires_at: string|null, remaining_seconds: int, allows_free_form: bool, block_reason: string|null}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'label' => $this->status->label(),
            'last_inbound_at' => $this->lastInboundAt?->toIso8601ZuluString(),
            'expires_at' => $this->expiresAt?->toIso8601ZuluString(),
            'remaining_seconds' => $this->remainingSeconds,
            'allows_free_form' => $this->allowsFreeFormMessages(),
            'block_reason' => $this->blockReason,
        ];
    }
}
