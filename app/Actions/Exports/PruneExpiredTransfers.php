<?php

namespace App\Actions\Exports;

use App\Enums\DataTransferStatus;
use App\Enums\DataTransferType;
use App\Models\DataTransfer;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes the files of expired transfers and marks them expired. The row
 * is kept as history (who exported what and when); only the file goes.
 */
class PruneExpiredTransfers
{
    /**
     * @return int Transfers expired.
     */
    public function handle(): int
    {
        $expired = 0;

        DataTransfer::query()->expired()->orderBy('id')->each(function (DataTransfer $transfer) use (&$expired): void {
            if ($transfer->path !== null && $transfer->disk !== null) {
                Storage::disk($transfer->disk)->delete($transfer->path);
            }

            // Uploaded import files that were never processed (or kept after a failure).
            $source = $transfer->options['source_path'] ?? null;

            if (is_string($source) && $source !== '' && $transfer->disk !== null) {
                Storage::disk($transfer->disk)->delete($source);
            }

            $transfer->update([
                'status' => DataTransferStatus::Expired,
                'path' => null,
                'error' => match (true) {
                    $transfer->status === DataTransferStatus::Draft => 'La importación no se confirmó a tiempo.',
                    $transfer->status->isPending() => $transfer->type === DataTransferType::Import ? 'La importación no terminó a tiempo.' : 'La exportación no terminó a tiempo.',
                    default => $transfer->error,
                },
                'expires_at' => $transfer->expires_at ?? now(),
            ]);

            $expired++;
        });

        return $expired;
    }
}
