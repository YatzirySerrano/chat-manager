<?php

namespace App\Console\Commands;

use App\Services\Operations\OperationalAlerts;
use Illuminate\Console\Command;

/**
 * Evaluates the critical operational conditions and delivers the alerts
 * (ops log channel + mail), each one at most once per throttle window.
 * Scheduled every five minutes; it can also run from its own cron line so
 * a stopped scheduler is still reported.
 */
class CheckOperationalAlerts extends Command
{
    protected $signature = 'ops:check-alerts {--dry-run : Only list the conditions, without delivering alerts}';

    protected $description = 'Detect critical operational failures and alert the administrators';

    public function handle(OperationalAlerts $alerts): int
    {
        $current = $alerts->evaluate();

        if ($current === []) {
            $this->info('Sin condiciones críticas.');

            return self::SUCCESS;
        }

        foreach ($current as $alert) {
            $this->warn("{$alert['title']}: {$alert['message']}");
        }

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $delivered = $alerts->deliver($current);
        $this->info('Alertas enviadas: '.count($delivered).' (las demás ya se avisaron dentro del intervalo).');

        return self::SUCCESS;
    }
}
