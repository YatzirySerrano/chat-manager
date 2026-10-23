<?php

namespace App\Listeners\Realtime;

use App\Broadcasting\RealtimeChannels;
use App\Broadcasting\RealtimeSignals;
use App\Events\Connections\ConnectionHealthChanged;
use App\Events\Connections\ConnectionStatusChanged;
use App\Models\Conversation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;

/**
 * A connection stopped or started being able to send (or Meta rejected its
 * token): every conversation of that connection still in attention gets a
 * "conversation.changed" signal on its own channel, so an open chat reloads
 * the conversation and its composer shows the current state. Only ids
 * travel; channels are sent in batches of 100 (Pusher protocol limit).
 */
class BroadcastConnectionChange implements ShouldQueue
{
    use QueuesRealtimeSignals;

    private const CHANNELS_PER_SIGNAL = 100;

    public function __construct(private RealtimeSignals $signals) {}

    public function handle(ConnectionStatusChanged $event): void
    {
        $this->signal($event->connectionId);
    }

    public function handleHealthChange(ConnectionHealthChanged $event): void
    {
        $this->signal($event->connectionId);
    }

    private function signal(int $connectionId): void
    {
        Conversation::query()
            ->where('connection_id', $connectionId)
            ->notClosed()
            ->select('id')
            ->chunkById(self::CHANNELS_PER_SIGNAL, function (Collection $conversations): void {
                $this->signals->send(
                    $conversations->map(fn (Conversation $conversation): string => RealtimeChannels::conversation($conversation->id)),
                    'conversation.changed',
                    ['reason' => 'connection_changed'],
                );
            });
    }
}
