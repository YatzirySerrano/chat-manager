<?php

namespace App\Events\TeamChat\Broadcasts;

use App\Support\Queues;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Invalidation signal on `team-chat.threads.{id}` (current participants
 * only). It carries identifiers and a reason, never message bodies or file
 * names: clients refetch through endpoints that authorize again.
 *
 * Names: `message.created` {message_id}, `thread.changed` {reason,
 * message_id?}, `read` {user_id, message_id}.
 */
class TeamThreadSignal implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array<string, int|string|null>  $payload
     */
    public function __construct(
        public readonly int $threadId,
        public readonly string $name,
        public readonly array $payload,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("team-chat.threads.{$this->threadId}")];
    }

    public function broadcastAs(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, int|string|null>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }

    public function broadcastQueue(): string
    {
        return Queues::name(Queues::NOTIFICATIONS);
    }
}
