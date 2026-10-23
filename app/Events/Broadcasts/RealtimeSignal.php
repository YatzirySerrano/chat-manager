<?php

namespace App\Events\Broadcasts;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * An invalidation signal pushed to private channels: "something you can see
 * changed, ask the server again". It only carries identifiers and minimal,
 * non-sensitive metadata (a reason, a status); never message bodies, phone
 * numbers, note text or file names. Clients refetch through endpoints that
 * authorize every request again, so a signal never grants access by itself.
 *
 * It is sent immediately because it is created inside a queued listener
 * (app/Listeners/Realtime), off the request path. The one exception is the
 * ephemeral "typing" signal of the internal chat, sent during its own
 * request (TeamThreadController::typing) and protected with rescue.
 */
class RealtimeSignal implements ShouldBroadcastNow
{
    /**
     * @param  list<string>  $channels  Channel names without the "private-" prefix.
     * @param  string  $event  Event name, listened to as ".{$event}" by the client.
     * @param  array<string, int|string|bool|null>  $payload
     */
    public function __construct(
        public readonly array $channels,
        public readonly string $event,
        public readonly array $payload,
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
        return $this->event;
    }

    /**
     * @return array<string, int|string|bool|null>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
