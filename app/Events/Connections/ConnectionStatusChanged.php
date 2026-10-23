<?php

namespace App\Events\Connections;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Whether a connection can send changed: it was activated, deactivated,
 * deleted or restored, or its credentials were verified, rejected or
 * replaced. Open conversations of the connection refresh their composer.
 * It only carries identifiers; listeners read the current state.
 */
class ConnectionStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  string  $reason  activated, deactivated, deleted, restored or credentials.
     */
    public function __construct(
        public readonly int $connectionId,
        public readonly string $reason,
    ) {}
}
