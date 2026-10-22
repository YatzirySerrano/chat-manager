<?php

namespace App\Events\TeamChat;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something about an internal thread changed: it was created, renamed,
 * archived, its participants changed, or a message was edited or deleted.
 *
 * `affectedUserIds` lists people who must refresh their thread list even if
 * they no longer participate (for instance someone just removed).
 */
class TeamThreadChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const PARTICIPANTS = 'participants';

    public const ARCHIVED = 'archived';

    public const UNARCHIVED = 'unarchived';

    public const MESSAGE_UPDATED = 'message.updated';

    public const MESSAGE_DELETED = 'message.deleted';

    /**
     * @param  list<int>  $affectedUserIds
     */
    public function __construct(
        public readonly int $threadId,
        public readonly string $reason,
        public readonly ?int $actorId = null,
        public readonly array $affectedUserIds = [],
        public readonly ?int $messageId = null,
    ) {}
}
