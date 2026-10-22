<?php

namespace App\Actions\TeamChat;

use App\Events\TeamChat\TeamThreadRead;
use App\Models\TeamChat\TeamParticipant;
use App\Models\TeamChat\TeamThread;

/**
 * Move the participant's read marker forward, up to the given message of
 * the thread (never backwards, never past the latest message). Returns
 * whether it moved.
 */
class MarkThreadRead
{
    public function handle(TeamThread $thread, TeamParticipant $participation, int $messageId): bool
    {
        $target = min($messageId, (int) $thread->last_message_id);

        if ($target <= 0) {
            return false;
        }

        $updated = TeamParticipant::query()
            ->whereKey($participation->id)
            ->whereNull('left_at')
            ->where(fn ($query) => $query->whereNull('last_read_message_id')->orWhere('last_read_message_id', '<', $target))
            ->update(['last_read_message_id' => $target, 'updated_at' => now()]);

        if ($updated === 0) {
            return false;
        }

        $participation->last_read_message_id = $target;

        TeamThreadRead::dispatch($thread->id, $participation->user_id, $target);

        return true;
    }
}
