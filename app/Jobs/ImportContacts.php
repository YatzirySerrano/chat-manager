<?php

namespace App\Jobs;

use App\Enums\DataTransferStatus;
use App\Models\DataTransfer;
use App\Services\Imports\ContactImporter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Processes a confirmed contacts import in batches. The transfer moves from
 * queued to processing under a row lock, so a duplicated job never runs the
 * same import twice; authorization is checked again by the importer. A
 * transfer is attempted once.
 */
class ImportContacts implements ShouldBeUnique, ShouldQueue
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
        return 'import-'.$this->transferId;
    }

    public function handle(ContactImporter $importer): void
    {
        $transfer = DB::transaction(function (): ?DataTransfer {
            $transfer = DataTransfer::query()->lockForUpdate()->find($this->transferId);

            if ($transfer === null || $transfer->status !== DataTransferStatus::Queued) {
                return null;
            }

            $transfer->update(['status' => DataTransferStatus::Processing, 'started_at' => now()]);

            return $transfer;
        });

        if ($transfer === null) {
            return;
        }

        @ini_set('memory_limit', (string) config('exports.memory_limit', '1024M'));

        $actor = $transfer->user()->first();

        if ($actor === null) {
            $importer->fail($transfer, null, 'La cuenta que solicitó la importación ya no existe.');

            return;
        }

        $importer->process($transfer, $actor);
    }

    /**
     * Unexpected errors end here: the user sees a generic message, details
     * stay in the failed jobs log.
     */
    public function failed(?Throwable $exception): void
    {
        $transfer = DataTransfer::query()->find($this->transferId);

        if ($transfer !== null && $transfer->status->isPending()) {
            app(ContactImporter::class)->fail($transfer, $transfer->user()->first(), 'No se pudo completar la importación. Las filas ya procesadas se conservan; vuelve a subir el archivo para continuar (no se duplicarán contactos).');
        }
    }
}
