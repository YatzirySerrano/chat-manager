<?php

namespace App\Events\DataTransfers;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A queued export or an import left processing: it completed or failed.
 * Dispatched by DataTransfer itself after commit; ids only.
 */
class DataTransferFinished implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $transferId,
        public readonly string $status,
    ) {}
}
