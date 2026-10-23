<?php

namespace App\Actions\Analytics;

use App\Models\Conversation;
use App\Services\Analytics\AnalyticsCache;
use App\Services\Analytics\ConversationMetricsCalculator;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes the metrics of every conversation (or of those that changed
 * since a moment) in chunks by id, so it never loads the whole table and
 * can be interrupted and run again: each row is recomputed from the source
 * data, so running it twice gives the same result.
 *
 * "Changed since" covers the conversation itself (created or updated) and
 * its messages (e.g. a delivery status that arrived late without touching
 * the conversation), through the index on messages.updated_at.
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
     * @param  bool  $refreshBusinessTime  Recompute the frozen business time with the schedules in force now.
     * @return int Conversations recomputed.
     */
    public function handle(?CarbonInterface $since = null, array $conversationIds = [], ?Closure $progress = null, bool $refreshBusinessTime = false): int
    {
        $processed = 0;

        $this->query($since, $conversationIds)->chunkById(self::CHUNK, function (Collection $conversations) use (&$processed, $progress, $refreshBusinessTime): void {
            foreach ($conversations as $conversation) {
                $this->calculator->recompute($conversation->id, $refreshBusinessTime);
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
                ->orWhere('created_at', '>=', $since)
                ->orWhereIn('id', DB::table('messages')->select('conversation_id')->where('updated_at', '>=', $since))));
    }
}
