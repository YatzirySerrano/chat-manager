<?php

namespace App\Notifications;

use App\Support\Queues;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Real-time signal "you have a new or updated notification", sent only to
 * the recipient's private channel (`users.{id}`, event `.notification`). It
 * carries the notification id and kind, never its content: the client
 * fetches the list again from the server, which checks access.
 *
 * It is a best-effort hint: when broadcasting is down the bell keeps
 * working through its periodic refresh, so it is attempted once.
 */
class NotificationSignal implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public int $tries = 1;

    public function __construct(
        public readonly int $userId,
        public readonly string $notificationId,
        public readonly string $kind,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("users.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'notification';
    }

    /**
     * @return array{id: string, kind: string}
     */
    public function broadcastWith(): array
    {
        return ['id' => $this->notificationId, 'kind' => $this->kind];
    }

    public function broadcastQueue(): string
    {
        return Queues::name(Queues::NOTIFICATIONS);
    }
}
