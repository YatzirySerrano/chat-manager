<?php

namespace App\Jobs;

use App\Models\DataTransfer;
use App\Services\Migration\History\HistoryInventory;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Rebuilds the inventory of a draft history import batch in the
 * background (after the admin says which Chat.txt participants are the
 * company), so a large batch never holds the HTTP request.
 */
class RebuildHistoryImportInventory implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public int $transferId)
    {
        $this->timeout = ParseHistoryImportFile::timeout();
    }

    public function uniqueId(): string
    {
        return 'history-import-rebuild-'.$this->transferId;
    }

    public function handle(HistoryInventory $inventory): void
    {
        $batch = DataTransfer::query()->find($this->transferId);

        if ($batch !== null) {
            $inventory->rebuild($batch);
        }
    }
}
