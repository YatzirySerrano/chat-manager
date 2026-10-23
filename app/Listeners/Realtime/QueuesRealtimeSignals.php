<?php

namespace App\Listeners\Realtime;

use App\Support\Queues;

/**
 * Queue settings shared by the real-time listeners: they run off the request
 * path on the dedicated "realtime" queue (REALTIME_QUEUE overrides it, see
 * Queues::name), never behind in-app notifications or exports, with a few
 * bounded attempts for database hiccups, and they are skipped entirely when
 * broadcasting is disabled (BROADCAST_CONNECTION=null), so installations
 * without real-time never queue useless work.
 */
trait QueuesRealtimeSignals
{
    public int $tries = 3;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [2, 10];
    }

    public function viaQueue(): string
    {
        return Queues::name(Queues::REALTIME);
    }

    public function viaConnection(): ?string
    {
        $connection = config('broadcasting.realtime.queue_connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function shouldQueue(object $event): bool
    {
        return config('broadcasting.default') !== 'null';
    }
}
