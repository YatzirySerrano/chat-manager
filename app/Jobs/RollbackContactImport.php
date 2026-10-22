<?php

namespace App\Jobs;

use App\Models\DataTransfer;
use App\Services\Imports\ImportBatchRollback;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs the confirmed logical rollback of a contacts import batch. The
 * rollback state moves from queued to processing under a row lock, so a
 * duplicated job does nothing, and every record is handled once (it is
 * marked when handled), so a retry after a failure never undoes twice.
 * Attempted once; the user may request it again if it fails.
 */
class RollbackContactImport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public int $transferId)
    {
        $this->timeout = (int) config('exports.job_timeout', 600);
    }

    public function uniqueId(): string
    {
        return 'import-rollback-'.$this->transferId;
    }

    public function handle(ImportBatchRollback $rollback): void
    {
        $transfer = DataTransfer::query()->find($this->transferId);

        if ($transfer === null) {
            return;
        }

        $actor = $transfer->user()->first();

        if ($actor === null) {
            $rollback->fail($transfer, null, 'La cuenta que solicitó la reversión ya no existe.');

            return;
        }

        $rollback->run($transfer, $actor);
    }

    public function failed(?Throwable $exception): void
    {
        $transfer = DataTransfer::query()->find($this->transferId);
        $rollback = app(ImportBatchRollback::class);

        if ($transfer !== null && in_array($rollback->state($transfer)['status'] ?? null, [ImportBatchRollback::STATUS_QUEUED, ImportBatchRollback::STATUS_PROCESSING], true)) {
            $rollback->fail($transfer, $transfer->user()->first(), 'No se pudo terminar la reversión. Lo ya revertido se conserva; puedes intentarlo de nuevo.');
        }
    }
}
