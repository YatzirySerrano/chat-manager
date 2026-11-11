<?php

namespace App\Console\Commands;

use App\Services\Sla\SlaEvaluator;
use Illuminate\Console\Command;

/**
 * Raises the automatic SLA alerts of the open response cycles: «por
 * vencer», «vencida» and the reminders of requests still unanswered.
 * Scheduled every minute. Safe to run at any time, twice at once or on
 * several servers: each transition is claimed atomically (SlaEvaluator).
 */
class EvaluateSla extends Command
{
    protected $signature = 'sla:evaluate';

    protected $description = 'Raise the SLA warnings, breaches and reminders of the requests waiting for an answer';

    public function handle(SlaEvaluator $evaluator): int
    {
        $result = $evaluator->run();

        $this->info("SLA: {$result['breached']} vencidas, {$result['warned']} por vencer, {$result['reminded']} recordatorios.");

        return self::SUCCESS;
    }
}
