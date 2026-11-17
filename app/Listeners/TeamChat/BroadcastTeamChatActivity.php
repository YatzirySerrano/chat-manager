<?php

namespace App\Listeners\TeamChat;

use App\Enums\TeamChat\TeamThreadType;
use App\Events\TeamChat\Broadcasts\TeamChatUserSignal;
use App\Events\TeamChat\Broadcasts\TeamThreadSignal;
use App\Events\TeamChat\TeamMessagePosted;
use App\Events\TeamChat\TeamThreadChanged;
use App\Events\TeamChat\TeamThreadRead;
use App\Models\TeamChat\TeamParticipant;
use App\Models\TeamChat\TeamThread;
use App\Services\TeamChat\ControlDesk;

/**
 * Turns internal chat domain events into real-time invalidation signals:
 * one on the thread channel (open chat windows) and one on the personal
 * channel of every current participant (thread list and unread badge).
 * Payloads only carry identifiers; clients refetch with authorization.
 * Registered by event discovery (one handler per event type).
 */
class BroadcastTeamChatActivity
{
    public function __construct(private ControlDesk $desk) {}

    public function handleMessagePosted(TeamMessagePosted $event): void
    {
        broadcast(new TeamThreadSignal($event->threadId, 'message.created', ['message_id' => $event->messageId]));

        // A «Mesa de control» thread is a shared queue: every auditor's desk
        // section follows its messages (previews, order, counters).
        $isDesk = TeamThread::query()->whereKey($event->threadId)->where('type', TeamThreadType::Desk)->exists();

        $this->signalUsers(
            array_values(array_unique([...$this->participantIds($event->threadId), ...($isDesk ? $this->desk->auditorIds() : [])])),
            $event->threadId,
            'message',
        );
    }

    public function handleThreadChanged(TeamThreadChanged $event): void
    {
        broadcast(new TeamThreadSignal($event->threadId, 'thread.changed', array_filter([
            'reason' => $event->reason,
            'message_id' => $event->messageId,
        ], fn (int|string|null $value): bool => $value !== null)));

        $this->signalUsers(
            array_values(array_unique([...$this->participantIds($event->threadId), ...$event->affectedUserIds])),
            $event->threadId,
            $event->reason,
        );
    }

    public function handleThreadRead(TeamThreadRead $event): void
    {
        broadcast(new TeamThreadSignal($event->threadId, 'read', ['user_id' => $event->userId, 'message_id' => $event->messageId]));

        $this->signalUsers([$event->userId], $event->threadId, 'read');
    }

    /**
     * @param  list<int>  $userIds
     */
    private function signalUsers(array $userIds, int $threadId, string $reason): void
    {
        if ($userIds !== []) {
            broadcast(new TeamChatUserSignal($userIds, $threadId, $reason));
        }
    }

    /**
     * @return list<int>
     */
    private function participantIds(int $threadId): array
    {
        return array_values(array_map('intval', TeamParticipant::query()
            ->where('team_thread_id', $threadId)
            ->whereNull('left_at')
            ->pluck('user_id')
            ->all()));
    }
}
