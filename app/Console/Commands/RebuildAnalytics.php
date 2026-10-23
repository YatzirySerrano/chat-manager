<?php

namespace App\Console\Commands;

use App\Actions\Analytics\RebuildConversationMetrics;
use Illuminate\Console\Command;

/**
 * Rebuilds the per-conversation metrics of the reports from the source
 * data. Safe to run at any time and as many times as needed. Business time
 * already computed is kept (frozen) unless --business-time is given.
 */
class RebuildAnalytics extends Command
{
    protected $signature = 'analytics:rebuild
        {--days= : Only conversations created or updated in the last N days}
        {--conversation=* : Only the given conversation ids}
        {--business-time : Also recompute the frozen business time with the schedules in force now}';

    protected $description = 'Recompute the report metrics of the conversations from their events and messages';

    public function handle(RebuildConversationMetrics $rebuild): int
    {
        $days = $this->option('days');

        if ($days !== null && (! is_numeric($days) || (int) $days < 1)) {
            $this->error('--days debe ser un número entero positivo.');

            return self::INVALID;
        }

        $since = $days === null ? null : now()->subDays((int) $days);
        $ids = array_values(array_map('intval', array_filter((array) $this->option('conversation'), 'is_numeric')));

        $bar = $this->output->createProgressBar($rebuild->count($since, $ids));
        $processed = $rebuild->handle($since, $ids, fn (int $chunk) => $bar->advance($chunk), (bool) $this->option('business-time'));
        $bar->finish();
        $this->newLine();

        $this->info("Métricas recalculadas: {$processed}");

        return self::SUCCESS;
    }
}
