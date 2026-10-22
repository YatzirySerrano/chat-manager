<?php

namespace App\Events\Users;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The role, permissions, departments or state of a user changed (or of a
 * role many users hold). Open sessions must revalidate what they can see:
 * real-time clients drop their channels and authorize them again.
 */
class UserAccessChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<int>  $userIds
     */
    public function __construct(
        public readonly array $userIds,
        public readonly string $reason,
    ) {}
}
