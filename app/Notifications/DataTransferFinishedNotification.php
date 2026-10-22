<?php

namespace App\Notifications;

use App\Enums\DataTransferType;

/**
 * A queued export or a contacts import requested by the recipient finished
 * (completed or failed).
 */
class DataTransferFinishedNotification extends InAppNotification
{
    public function __construct(
        public readonly int $transferId,
        public readonly DataTransferType $type,
        public readonly string $status,
    ) {}

    public function kind(): NotificationKind
    {
        return $this->type === DataTransferType::Import ? NotificationKind::ImportFinished : NotificationKind::ExportFinished;
    }

    public function sourceKey(): string
    {
        return "transfer:{$this->transferId}:{$this->status}";
    }

    public function payload(): array
    {
        return [
            'transfer_id' => $this->transferId,
            'status' => $this->status,
        ];
    }
}
