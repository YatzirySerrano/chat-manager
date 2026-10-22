<?php

namespace App\Events\TeamChat;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A message (or a system line) was added to an internal thread. Dispatched
 * after commit with identifiers only: listeners (real-time signals,
 * notifications) read the current state, and its access rules, from the
 * database.
 */
class TeamMessagePosted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  string  $kind  A TeamMessageKind value.
     */
    public function __construct(
        public readonly int $threadId,
        public readonly int $messageId,
        public readonly ?int $authorId,
        public readonly string $kind,
    ) {}
}
