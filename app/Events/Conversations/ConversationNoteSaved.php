<?php

namespace App\Events\Conversations;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An internal note of a conversation was added or edited (never sent to the
 * customer). Dispatched after commit; it carries identifiers only.
 */
class ConversationNoteSaved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $conversationId,
        public readonly int $noteId,
        public readonly ?int $actorId = null,
    ) {}
}
