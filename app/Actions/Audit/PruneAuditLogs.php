<?php

namespace App\Actions\Audit;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Deletes audit entries in bounded batches with the query builder, never
 * Eloquent: the AuditLog model is append-only (it refuses `deleting`), and a
 * query-builder delete bypasses that guard. Used by the daily retention
 * (audit:prune) and by the manual bulk deletion of the «Auditoría» screen.
 */
class PruneAuditLogs
{
    /**
     * Daily retention: delete entries older than the configured number of
     * days. 0 (or less) keeps everything.
     *
     * @return int Entries deleted.
     */
    public function handle(?int $days = null): int
    {
        $days ??= (int) config('audit.retention_days', 180);

        if ($days <= 0) {
            return 0;
        }

        return $this->deleteOlderThan(now()->subDays($days));
    }

    /**
     * Manual bulk deletion up to a cutoff. A null cutoff deletes every entry.
     *
     * @return int Entries deleted.
     */
    public function purge(?CarbonInterface $before): int
    {
        return $this->deleteOlderThan($before);
    }

    /**
     * @return int Entries deleted.
     */
    private function deleteOlderThan(?CarbonInterface $cutoff): int
    {
        $chunk = max(100, (int) config('audit.prune_chunk', 5000));
        $total = 0;

        do {
            $deleted = DB::table('audit_logs')
                ->when($cutoff !== null, fn ($query) => $query->where('created_at', '<', $cutoff))
                ->limit($chunk)
                ->delete();

            $total += $deleted;
        } while ($deleted > 0);

        return $total;
    }
}
