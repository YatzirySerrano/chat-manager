<?php

namespace App\Events\Connections;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The operational health of a WhatsApp connection changed, for instance
 * when Meta starts rejecting its token. Administrators may be notified.
 */
class ConnectionHealthChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $connectionId,
        public readonly string $health,
        public readonly ?string $previousHealth = null,
        public readonly ?string $detail = null,
    ) {}
}
