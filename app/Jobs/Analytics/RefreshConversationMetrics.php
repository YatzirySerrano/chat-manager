<?php

namespace App\Jobs\Analytics;

use App\Services\Analytics\AnalyticsCache;
use App\Services\Analytics\ConversationMetricsCalculator;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Recomputes the metrics row of one conversation. While a refresh of the
 * same conversation is waiting in the queue no other is queued, so a burst
 * of messages costs one recomputation; once it starts, a new change queues
 * a new refresh, so the last state is always captured. The recomputation
 * reads the source data, so retries and late runs are harmless.
 */
class RefreshConversationMetrics implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [5, 30];

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(public int $conversationId)
    {
        $this->onQueue(Queues::name(Queues::DEFAULT));
    }

    public function uniqueId(): string
    {
        return (string) $this->conversationId;
    }

    public function handle(ConversationMetricsCalculator $calculator, AnalyticsCache $cache): void
    {
        Cache::lock("analytics:conversation:{$this->conversationId}", 30)
            ->block(10, fn () => $calculator->recompute($this->conversationId));

        $cache->touch();
    }
}
