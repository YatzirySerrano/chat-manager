<?php

namespace App\Console\Commands;

use App\Actions\Audit\PruneAuditLogs as PruneAction;
use Illuminate\Console\Command;

/**
 * Retention of the audit log: deletes entries older than
 * audit.retention_days so the append-only audit_logs table does not grow
 * without bound.
 */
class PruneAuditLogs extends Command
{
    protected $signature = 'audit:prune';

    protected $description = 'Delete audit entries older than the configured retention (audit.retention_days)';

    public function handle(PruneAction $prune): int
    {
        $deleted = $prune->handle();

        $this->info($deleted === 0
            ? 'Auditoría: nada que depurar.'
            : "Auditoría: {$deleted} registros antiguos eliminados.");

        return self::SUCCESS;
    }
}
