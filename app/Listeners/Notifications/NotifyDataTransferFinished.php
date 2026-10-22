<?php

namespace App\Listeners\Notifications;

use App\Enums\DataTransferStatus;
use App\Events\DataTransfers\DataTransferFinished;
use App\Models\DataTransfer;
use App\Notifications\DataTransferFinishedNotification;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The owner of a queued export or of an import learns it finished, so they
 * do not have to keep the page open.
 */
class NotifyDataTransferFinished implements ShouldQueue
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30];

    public function viaQueue(): string
    {
        return Queues::name(Queues::NOTIFICATIONS);
    }

    public function handle(DataTransferFinished $event): void
    {
        $transfer = DataTransfer::query()->with('user')->find($event->transferId);
        $status = DataTransferStatus::tryFrom($event->status);

        if ($transfer === null || $transfer->user === null || $status === null) {
            return;
        }

        $transfer->user->notify(new DataTransferFinishedNotification($transfer->id, $transfer->type, $status->value));
    }
}
