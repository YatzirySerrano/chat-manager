<?php

namespace App\Console\Commands;

use App\Actions\Conversations\AssignAutomatically;
use App\Enums\DistributionMode;
use App\Models\Department;
use App\Services\Conversations\QueueRules;
use App\Support\Features;
use Illuminate\Console\Command;
use Throwable;

/**
 * Offers the queue of an automatic department again when it holds a request
 * that can be taken and nothing else happened that would have offered it
 * (nobody accepted, closed or released anything meanwhile): the minimum
 * wait of a request ended, or the rules that were holding it changed.
 * Scheduled every minute.
 *
 * It does not depend on when the request became eligible, so nothing is
 * lost when the scheduler was stopped for a while or a rule was shortened
 * or turned off: whatever is eligible now is offered now.
 *
 * Cheap when nothing is waiting: one indexed EXISTS per active automatic
 * department (QueueRules::hasEligible(), the same definition of "eligible"
 * the engine uses), and only then is the queue distributed (bounded,
 * AssignAutomatically::distributeQueue(), which stops at the first request
 * nobody has room for). Running it twice, late or on several servers is
 * harmless: every assignment is decided under the engine's locks.
 */
class OfferWaitingQueues extends Command
{
    protected $signature = 'conversations:offer-waiting-queues';

    protected $description = 'Offer automatic queues again while they hold requests that can be taken';

    public function handle(QueueRules $queueRules, AssignAutomatically $assignAutomatically): int
    {
        // Phase two only: without minimum wait nor chronological order
        // (FEATURE_PHASE_TWO off) nothing is ever held back to offer again.
        if (! Features::phaseTwo()) {
            $this->info('Reglas de la cola de la fase dos desactivadas: no hay colas que ofrecer.');

            return self::SUCCESS;
        }

        $assigned = 0;

        foreach (Department::query()->active()->where('distribution_mode', DistributionMode::Automatic)->orderBy('id')->get() as $department) {
            try {
                if ($queueRules->hasEligible($department->id)) {
                    $assigned += $assignAutomatically->distributeQueue($department);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $this->info("Conversaciones asignadas de las colas automáticas: {$assigned}");

        return self::SUCCESS;
    }
}
