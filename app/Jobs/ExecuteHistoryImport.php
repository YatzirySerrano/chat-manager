<?php

namespace App\Jobs;

use App\Enums\DataTransferStatus;
use App\Enums\Permission;
use App\Models\DataTransfer;
use App\Models\User;
use App\Services\Migration\History\HistoryImportExecutor;
use App\Services\Migration\History\HistoryImportPaused;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Executes a confirmed Whaticket history import (HistoryImportExecutor)
 * for at most history-import.job_seconds, then queues itself again until
 * every decided conversation is done: a long batch never holds a worker
 * (or a lock) for hours, and a crashed run is simply continued by the next
 * one (or by "Reanudar"), skipping what is done. A cache lock keeps a
 * single run per batch; the admin who confirmed it must still hold
 * history-import.execute.
 */
class ExecuteHistoryImport implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    /**
     * A queued run that never started (worker gone) stops blocking "Reanudar" after this.
     */
    public int $uniqueFor = 900;

    public function __construct(public int $transferId)
    {
        // The run stops itself at job_seconds; the hard timeout stays below the queue's retry_after.
        $this->timeout = min(ParseHistoryImportFile::timeout(), max(60, (int) config('history-import.job_seconds', 240) + 120));
    }

    public function uniqueId(): string
    {
        return 'history-import-'.$this->transferId;
    }

    public function handle(HistoryImportExecutor $executor): void
    {
        $lock = Cache::lock('history-import:execute:'.$this->transferId, $this->timeout);

        if (! $lock->get()) {
            return;
        }

        $finished = true;

        try {
            $batch = DB::transaction(function (): ?DataTransfer {
                $batch = DataTransfer::query()->lockForUpdate()->find($this->transferId);

                if ($batch === null || ! in_array($batch->status, [DataTransferStatus::Queued, DataTransferStatus::Processing], true)) {
                    return null;
                }

                $batch->forceFill(['status' => DataTransferStatus::Processing, 'started_at' => $batch->started_at ?? now()])->saveQuietly();

                return $batch;
            });

            if ($batch === null) {
                return;
            }

            @ini_set('memory_limit', (string) config('exports.memory_limit', '1024M'));

            $confirmedBy = $batch->options['confirmed_by'] ?? $batch->user_id;
            $actor = is_numeric($confirmedBy) ? User::query()->find((int) $confirmedBy) : null;

            if ($actor === null || ! $actor->holdsPermission(Permission::ExecuteHistoryImports)) {
                $executor->fail($batch, $actor, 'La cuenta que confirmó o reanudó la importación ya no puede ejecutarla. Lo importado hasta ahora se conserva; otra persona autorizada puede reanudarla.');

                return;
            }

            try {
                $finished = $executor->run($batch, $actor, null, microtime(true) + max(10, (int) config('history-import.job_seconds', 240)));
            } catch (HistoryImportPaused $paused) {
                $executor->fail($batch, $actor, $paused->getMessage());
            }
        } finally {
            $lock->release();
        }

        if (! $finished) {
            self::dispatch($this->transferId)->onQueue($this->queue);
        }
    }

    /**
     * Unexpected errors outside one conversation end here: the batch stays
     * resumable and nothing already imported is lost or repeated.
     */
    public function failed(?Throwable $exception): void
    {
        // A run killed by its timeout never released its lock.
        Cache::lock('history-import:execute:'.$this->transferId)->forceRelease();

        $batch = DataTransfer::query()->find($this->transferId);

        if ($batch !== null && $batch->status->isPending()) {
            app(HistoryImportExecutor::class)->fail($batch, null, 'La importación se interrumpió. Lo importado se conserva; usa «Reanudar» para continuar sin duplicar.');
        }
    }
}
