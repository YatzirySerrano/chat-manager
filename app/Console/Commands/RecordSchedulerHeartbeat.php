<?php

namespace App\Console\Commands;

use App\Services\Operations\SchedulerHeartbeat;
use Illuminate\Console\Command;

/**
 * Scheduled every minute: proves the scheduler is alive (system page,
 * readiness check and alerts read the age of the newest beat).
 */
class RecordSchedulerHeartbeat extends Command
{
    protected $signature = 'ops:heartbeat';

    protected $description = 'Record that the scheduler is running';

    public function handle(SchedulerHeartbeat $heartbeat): int
    {
        $this->info('Latido del programador registrado: '.$heartbeat->beat()->toIso8601String());

        return self::SUCCESS;
    }
}
