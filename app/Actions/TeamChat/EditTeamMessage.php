<?php

namespace App\Actions\TeamChat;

use App\Events\TeamChat\TeamThreadChanged;
use App\Models\TeamChat\TeamMessage;
use App\Models\User;

/**
 * The author corrects the text of their message; it is marked as edited.
 * Returns whether the text changed.
 */
class EditTeamMessage
{
    public function handle(TeamMessage $message, User $actor, ?string $body): bool
    {
        if ($message->body === $body) {
            return false;
        }

        $message->forceFill(['body' => $body, 'edited_at' => now()])->save();

        TeamThreadChanged::dispatch($message->team_thread_id, TeamThreadChanged::MESSAGE_UPDATED, $actor->id, [], $message->id);

        return true;
    }
}
