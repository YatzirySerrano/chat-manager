<?php

namespace App\Notifications;

/**
 * A WhatsApp connection degraded (provider errors, rejected credentials).
 * Repeated reports fold into one unread notification per connection; the
 * same transition reported again within the hour is ignored.
 */
class ConnectionProblemNotification extends InAppNotification
{
    public function __construct(
        public readonly int $connectionId,
        public readonly string $health,
        public readonly ?string $previousHealth,
        public readonly string $bucket,
    ) {}

    public function kind(): NotificationKind
    {
        return NotificationKind::ConnectionProblem;
    }

    public function sourceKey(): string
    {
        return "connection:{$this->connectionId}:".($this->previousHealth ?? 'none')."->{$this->health}:{$this->bucket}";
    }

    public function aggregateKey(): string
    {
        return "connection.problem:{$this->connectionId}";
    }

    public function payload(): array
    {
        return [
            'connection_id' => $this->connectionId,
            'health' => $this->health,
        ];
    }
}
