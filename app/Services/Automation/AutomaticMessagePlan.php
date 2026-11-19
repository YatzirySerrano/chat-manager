<?php

namespace App\Services\Automation;

use App\Models\User;

/**
 * The automatic message chosen for a conversation and where its text came
 * from ("department" or "connection"), kept for traceability. The agent,
 * when given, is the one {{agente}} names (the action messages).
 */
final readonly class AutomaticMessagePlan
{
    public function __construct(
        public AutomaticMessageKind $kind,
        public string $body,
        public string $source,
        public ?User $agent = null,
    ) {}
}
