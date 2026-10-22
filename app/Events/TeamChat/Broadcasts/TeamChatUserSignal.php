<?php

namespace App\Events\TeamChat\Broadcasts;

use App\Support\Queues;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * `.team-chat.changed` {thread_id, reason} on `users.{id}`: the thread list
 * or the unread badge of those people must be refreshed.
 */
class TeamChatUserSignal implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  list<int>  $userIds
     */
    public function __construct(
        public readonly array $userIds,
        public readonly int $threadId,
        public readonly string $reason,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(fn (int $userId): PrivateChannel => new PrivateChannel("users.{$userId}"), $this->userIds);
    }

    public function broadcastAs(): string
    {
        return 'team-chat.changed';
    }

    /**
     * @return array{thread_id: int, reason: string}
     */
    public function broadcastWith(): array
    {
        return ['thread_id' => $this->threadId, 'reason' => $this->reason];
    }

    public function broadcastQueue(): string
    {
        return Queues::name(Queues::NOTIFICATIONS);
    }

    /**
     * Nothing to send when nobody is affected.
     */
    public function broadcastWhen(): bool
    {
        return $this->userIds !== [];
    }
}
