<?php

namespace App\Notifications;

/**
 * A connection degraded (provider errors, rejected credentials) or, with
 * `recovered`, the same connection works again. Repeated reports fold into
 * one unread notification per connection, so a recovery replaces the unread
 * problem instead of adding a second notification; the same transition
 * reported again within the hour is ignored.
 */
class ConnectionProblemNotification extends InAppNotification
{
    public function __construct(
        public readonly int $connectionId,
        public readonly string $health,
        public readonly ?string $previousHealth,
        public readonly string $bucket,
        public readonly bool $recovered = false,
    ) {}

    public function kind(): NotificationKind
    {
        return NotificationKind::ConnectionProblem;
    }

    /**
     * A recovery is good news to read when convenient, not an emergency.
     */
    public function level(): NotificationLevel
    {
        return $this->recovered ? NotificationLevel::Informative : parent::level();
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
            ...($this->recovered ? ['recovered' => true] : []),
        ];
    }

    /**
     * The newest report replaces the previous one: a problem after a
     * recovery is a problem again.
     */
    public function aggregate(array $existing): array
    {
        return $this->payload();
    }
}
