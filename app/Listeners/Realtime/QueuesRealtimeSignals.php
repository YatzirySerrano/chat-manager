<?php

namespace App\Listeners\Realtime;

use App\Support\Queues;
use Illuminate\Support\Facades\Log;

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

    /**
     * Debug log of the fan-out of one signal: queue_wait_ms (event raised →
     * listener started) and publish_ms (listener started → published).
     * Identifiers only (the job's log context); never content.
     */
    protected function logFanOut(string $signal, ?float $recordedAt, float $startedAt): void
    {
        $finishedAt = microtime(true);

        Log::debug('Real-time signal published.', [
            'signal' => $signal,
            'queue_wait_ms' => $recordedAt === null ? null : max(0, (int) round(($startedAt - $recordedAt) * 1000)),
            'publish_ms' => max(0, (int) round(($finishedAt - $startedAt) * 1000)),
        ]);
    }
}
