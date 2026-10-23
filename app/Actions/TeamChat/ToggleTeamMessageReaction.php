<?php

namespace App\Actions\TeamChat;

use App\Events\TeamChat\TeamThreadChanged;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamMessageReaction;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Add or withdraw the actor's reaction to an internal message. Both are
 * idempotent (the unique key absorbs double clicks and concurrent requests)
 * and notify nobody; participants only receive the usual "message changed"
 * signal, which makes them fetch the message again.
 *
 * Adding repeats the authorization while holding the thread row (the lock
 * taken by removals, departures and archiving), so nobody reacts after
 * losing access, and on the message row read again under lock (the row
 * DeleteTeamMessage updates), so no reaction lands on a message deleted
 * after the request loaded it.
 */
class ToggleTeamMessageReaction
{
    /**
     * Returns whether a reaction was actually added.
     */
    public function add(TeamMessage $message, User $actor, string $emoji): bool
    {
        $inserted = DB::transaction(function () use ($message, $actor, $emoji): bool {
            $thread = TeamThread::query()->whereKey($message->team_thread_id)->lockForUpdate()->firstOrFail();
            $current = TeamMessage::query()->whereKey($message->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('react', [$thread, $current]);

            return TeamMessageReaction::query()->insertOrIgnore([
                'team_message_id' => $message->id,
                'user_id' => $actor->id,
                'emoji' => $emoji,
                'created_at' => now(),
                'updated_at' => now(),
            ]) > 0;
        });

        if ($inserted) {
            $this->announce($message, $actor);
        }

        return $inserted;
    }

    /**
     * Returns whether a reaction was actually removed.
     */
    public function remove(TeamMessage $message, User $actor, string $emoji): bool
    {
        $deleted = TeamMessageReaction::query()
            ->where('team_message_id', $message->id)
            ->where('user_id', $actor->id)
            ->where('emoji', $emoji)
            ->delete() > 0;

        if ($deleted) {
            $this->announce($message, $actor);
        }

        return $deleted;
    }

    private function announce(TeamMessage $message, User $actor): void
    {
        TeamThreadChanged::dispatch($message->team_thread_id, TeamThreadChanged::MESSAGE_UPDATED, $actor->id, [], $message->id);
    }
}
