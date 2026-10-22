<?php

namespace App\Services\Automation;

/**
 * The automatic message chosen for a conversation and where its text came
 * from ("department" or "connection"), kept for traceability.
 */
final readonly class AutomaticMessagePlan
{
    public function __construct(
        public AutomaticMessageKind $kind,
        public string $body,
        public string $source,
    ) {}
}
