<?php

namespace App\Actions\HistoryImport;

use App\Enums\DataTransferStatus;
use App\Enums\DataTransferType;
use App\Models\DataTransfer;
use App\Models\HistoryImportConversation;
use App\Models\HistoryImportFile;
use App\Services\Migration\History\HistoryFileStore;
use App\Services\Migration\History\HistoryImportBatches;

/**
 * Retention of the Whaticket history importer (daily): a batch not
 * confirmed or not finished within history-import.retention_days, or a
 * finished one whose report expired, loses its stored files, staged
 * messages (and, if it never ran, its inventory) and every identifying
 * field of its inventory and files (names, numbers, participants, original
 * identifiers, error texts, file names), and is marked expired. What was
 * imported is never touched.
 */
class PruneHistoryImports
{
    public function __construct(private HistoryImportBatches $batches, private HistoryFileStore $store) {}

    /**
     * @return int Batches expired.
     */
    public function handle(): int
    {
        $days = max(1, (int) config('history-import.retention_days', 30));
        $expired = 0;

        DataTransfer::query()
            ->where('type', DataTransferType::HistoryImport)
            ->where('status', '!=', DataTransferStatus::Expired)
            ->where(fn ($query) => $query
                ->where(fn ($stale) => $stale
                    ->whereIn('status', [DataTransferStatus::Draft, DataTransferStatus::Queued, DataTransferStatus::Processing, DataTransferStatus::Failed])
                    ->where('updated_at', '<=', now()->subDays($days)))
                ->orWhere(fn ($finished) => $finished
                    ->where('status', DataTransferStatus::Completed)
                    ->where('expires_at', '<=', now())))
            ->orderBy('id')
            ->each(function (DataTransfer $batch) use (&$expired): void {
                $neverRan = $batch->status === DataTransferStatus::Draft;
                $this->batches->purgeStaging($batch, $this->store, $neverRan);

                // Whatever state it reached, no name, number, participant,
                // original identifier or error text outlives the retention:
                // only keys, dates, counts, decisions and results stay.
                HistoryImportConversation::query()->where('data_transfer_id', $batch->id)->update(HistoryImportBatches::minimizedColumns());
                HistoryImportFile::query()->where('data_transfer_id', $batch->id)->update(['original_name' => 'archivo', 'headers' => null, 'issues' => null, 'media_index' => null, 'error' => null]);

                $batch->forceFill([
                    'status' => DataTransferStatus::Expired,
                    'error' => match ($batch->status) {
                        DataTransferStatus::Draft => 'La importación de historial no se confirmó a tiempo; sus archivos se eliminaron.',
                        DataTransferStatus::Completed => $batch->error,
                        default => 'La importación de historial no terminó a tiempo; lo importado se conserva y sus archivos se eliminaron.',
                    },
                    'expires_at' => $batch->expires_at ?? now(),
                ])->saveQuietly();

                $expired++;
            });

        return $expired;
    }
}
