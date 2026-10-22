<?php

namespace App\Events\TeamChat;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A participant read an internal thread up to the given message.
 */
class TeamThreadRead implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $threadId,
        public readonly int $userId,
        public readonly int $messageId,
    ) {}
}
