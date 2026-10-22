<?php

namespace App\Events\Users;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class AgentAvailabilityChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $userId,
        public readonly string $availability,
    ) {}
}
