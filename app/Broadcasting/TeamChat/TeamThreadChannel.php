<?php

namespace App\Broadcasting\TeamChat;

use App\Broadcasting\RealtimeAudience;
use App\Broadcasting\RealtimeChannels;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Authorizes "team-chat.threads.{threadId}": only current participants who
 * may use the internal chat, evaluated on the user read again from the
 * database. No role (Superadministrador included) listens to threads it
 * does not participate in.
 */
class TeamThreadChannel
{
    public const NAME = 'team-chat.threads.{threadId}';

    public function __construct(private RealtimeAudience $audience) {}

    public function join(User $user, string $threadId): bool
    {
        $id = RealtimeChannels::identifier($threadId);
        $fresh = $this->audience->freshUser($user);

        if ($id === null || $fresh === null) {
            return false;
        }

        $thread = TeamThread::query()->find($id);

        return $thread !== null && Gate::forUser($fresh)->allows('view', $thread);
    }
}
