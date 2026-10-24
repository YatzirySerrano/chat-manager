<?php

namespace App\Console\Commands;

use App\Models\WebChatSession;
use Illuminate\Console\Command;

/**
 * Deletes web chat visitor sessions that expired or were revoked more than
 * webchat.prune_after_days ago. Contacts, conversations and messages are
 * never touched: only the session (its token hash, origin and IP hash).
 */
class PruneWebChatSessions extends Command
{
    protected $signature = 'webchat:prune-sessions';

    protected $description = 'Delete web chat visitor sessions expired or revoked long ago';

    public function handle(): int
    {
        $cutoff = now()->subDays(max(1, (int) config('webchat.prune_after_days', 30)));
        $deleted = 0;

        // One DELETE per column, each walking its own index: an OR of both
        // would scan the whole table on every batch.
        foreach (['expires_at', 'revoked_at'] as $column) {
            do {
                $batch = WebChatSession::query()
                    ->where($column, '<', $cutoff)
                    ->limit(1000)
                    ->delete();

                $deleted += $batch;
            } while ($batch === 1000);
        }

        $this->info("Sesiones de chat web eliminadas: {$deleted}");

        return self::SUCCESS;
    }
}
