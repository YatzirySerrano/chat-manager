<?php

namespace App\Listeners\Realtime;

use App\Broadcasting\RealtimeSignals;
use App\Events\DataTransfers\DataTransferFinished;
use App\Models\DataTransfer;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A queued export or import finished: only its owner is told, so the
 * Exportaciones list or the import progress refresh without waiting.
 */
class BroadcastDataTransferFinished implements ShouldQueue
{
    use QueuesRealtimeSignals;

    public function __construct(private RealtimeSignals $signals) {}

    public function handle(DataTransferFinished $event): void
    {
        $transfer = DataTransfer::query()->find($event->transferId);

        if ($transfer === null || $transfer->user_id === null) {
            return;
        }

        $this->signals->toUsers([$transfer->user_id], 'transfer.changed', [
            'transfer_id' => $transfer->id,
            'type' => $transfer->type->value,
            'status' => $transfer->status->value,
        ]);
    }
}
