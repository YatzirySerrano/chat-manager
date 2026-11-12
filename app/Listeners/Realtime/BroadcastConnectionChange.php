<?php

namespace App\Listeners\Realtime;

use App\Broadcasting\RealtimeChannels;
use App\Broadcasting\RealtimeSignals;
use App\Enums\Permission;
use App\Events\Connections\ConnectionHealthChanged;
use App\Events\Connections\ConnectionStatusChanged;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
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

        // WhatsApp Web (QR): the state of the session changed (QR scanned,
        // connected, lost). Whoever may see connections gets a signal on
        // their personal channel with the id only; the screen then reads the
        // state (and the QR, if allowed) from the server. Polling remains
        // the fallback when the real-time server is off.
        if ($event->reason === 'web_session') {
            $this->signals->toUsers(
                User::query()
                    ->active()
                    ->where(fn (Builder $query) => $query
                        ->withPermission(Permission::ViewConnections)
                        ->orWhere(fn (Builder $manage) => $manage->withPermission(Permission::ManageConnections)))
                    ->pluck('id'),
                'connection.session',
                ['connection_id' => $event->connectionId],
            );
        }
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
