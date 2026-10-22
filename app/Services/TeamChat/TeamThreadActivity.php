<?php

namespace App\Services\TeamChat;

use App\Enums\TeamChat\TeamChatSystemEvent;
use App\Enums\TeamChat\TeamMessageKind;
use App\Events\TeamChat\TeamMessagePosted;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamThread;
use App\Models\User;

/**
 * Bookkeeping shared by every write of the internal chat: system lines that
 * make group changes visible to all participants, the thread's latest
 * message pointer, and the domain event announcing a new message.
 */
class TeamThreadActivity
{
    /**
     * Record a visible system line in the thread.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function recordSystemEvent(TeamThread $thread, ?User $actor, TeamChatSystemEvent $event, ?int $targetUserId = null, array $metadata = []): TeamMessage
    {
        $message = new TeamMessage([
            'user_id' => $actor?->id,
            'kind' => TeamMessageKind::System,
            'metadata' => [...$metadata, 'event' => $event->value, 'target_user_id' => $targetUserId],
        ]);
        $message->team_thread_id = $thread->id;
        $message->save();

        $this->messageAdded($thread, $message);

        return $message;
    }

    /**
     * Move the thread's latest message pointer forward and announce the
     * message once the transaction commits. The pointer only ever moves
     * forward, so two messages sent at the same moment can never leave it
     * on the older one (which would hide the newest message from previews
     * and keep it from being marked as read).
     */
    public function messageAdded(TeamThread $thread, TeamMessage $message): void
    {
        TeamThread::query()
            ->whereKey($thread->id)
            ->where(fn ($pointer) => $pointer->whereNull('last_message_id')->orWhere('last_message_id', '<', $message->id))
            ->update([
                'last_message_id' => $message->id,
                'last_message_at' => $message->created_at,
                'updated_at' => now(),
            ]);

        if ($thread->last_message_id === null || $thread->last_message_id < $message->id) {
            $thread->forceFill(['last_message_id' => $message->id, 'last_message_at' => $message->created_at])->syncOriginalAttributes(['last_message_id', 'last_message_at']);
        }

        TeamMessagePosted::dispatch($thread->id, $message->id, $message->user_id, $message->kind->value);
    }
}
