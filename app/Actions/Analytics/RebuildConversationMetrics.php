<?php

namespace App\Actions\Analytics;

use App\Models\Conversation;
use App\Services\Analytics\AnalyticsCache;
use App\Services\Analytics\ConversationMetricsCalculator;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Recomputes the metrics of every conversation (or of those that changed
 * since a moment) in chunks by id, so it never loads the whole table and
 * can be interrupted and run again: each row is recomputed from the source
 * data, so running it twice gives the same result.
 */
class RebuildConversationMetrics
{
    public const CHUNK = 500;

    public function __construct(
        private ConversationMetricsCalculator $calculator,
        private AnalyticsCache $cache,
    ) {}

    /**
     * @param  list<int>  $conversationIds  Only these conversations (empty = all).
     * @param  (Closure(int): void)|null  $progress  Called after each chunk with its size.
     * @return int Conversations recomputed.
     */
    public function handle(?CarbonInterface $since = null, array $conversationIds = [], ?Closure $progress = null): int
    {
        $processed = 0;

        $this->query($since, $conversationIds)->chunkById(self::CHUNK, function (Collection $conversations) use (&$processed, $progress): void {
            foreach ($conversations as $conversation) {
                $this->calculator->recompute($conversation->id);
            }

            $processed += $conversations->count();

            if ($progress !== null) {
                $progress($conversations->count());
            }
        });

        $this->cache->flush();

        return $processed;
    }

    /**
     * @param  list<int>  $conversationIds
     */
    public function count(?CarbonInterface $since = null, array $conversationIds = []): int
    {
        return $this->query($since, $conversationIds)->count();
    }

    /**
     * @param  list<int>  $conversationIds
     * @return Builder<Conversation>
     */
    private function query(?CarbonInterface $since, array $conversationIds): Builder
    {
        return Conversation::query()
            ->select('id')
            ->when($conversationIds !== [], fn (Builder $query) => $query->whereKey($conversationIds))
            ->when($since !== null, fn (Builder $query) => $query->where(fn (Builder $changed) => $changed
                ->where('updated_at', '>=', $since)
                ->orWhere('created_at', '>=', $since)));
    }
}
