<?php

namespace App\Notifications;

/**
 * An administrator changed the recipient's role, permissions, departments or
 * account state. Changes close in time fold into one unread notification.
 */
class AccessChangedNotification extends InAppNotification
{
    public function __construct(
        public readonly string $reason,
        public readonly string $bucket,
    ) {}

    public function kind(): NotificationKind
    {
        return NotificationKind::AccessChanged;
    }

    public function sourceKey(): string
    {
        return "access:{$this->reason}:{$this->bucket}";
    }

    public function aggregateKey(): string
    {
        return 'account.access';
    }

    public function payload(): array
    {
        return ['reason' => mb_substr($this->reason, 0, 60)];
    }
}
