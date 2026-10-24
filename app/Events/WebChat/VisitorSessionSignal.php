<?php

namespace App\Events\WebChat;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Invalidation signal pushed to the private channels of web chat visitor
 * sessions ('webchat.sessions.{public_id}'): "your conversation changed,
 * ask the server again". It never carries content, only the kind of change
 * and, for messages, the cursor to resume from; the widget refetches
 * through the authenticated visitor API.
 *
 * It is broadcast immediately because it is created inside the queued
 * SignalVisitorSessions listener, which already runs on the "realtime"
 * queue, off the request path (the same pattern as RealtimeSignal).
 */
class VisitorSessionSignal implements ShouldBroadcastNow
{
    /**
     * @param  list<string>  $channels  Session channel names without the "private-" prefix.
     * @param  'messages'|'conversation'  $type
     */
    public function __construct(
        public readonly array $channels,
        public readonly string $type,
        public readonly ?string $cursor = null,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(fn (string $channel): PrivateChannel => new PrivateChannel($channel), $this->channels);
    }

    public function broadcastAs(): string
    {
        return 'webchat.signal';
    }

    /**
     * @return array{type: string, cursor?: string}
     */
    public function broadcastWith(): array
    {
        return $this->cursor === null ? ['type' => $this->type] : ['type' => $this->type, 'cursor' => $this->cursor];
    }
}
