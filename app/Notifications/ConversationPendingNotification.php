<?php

namespace App\Notifications;

/**
 * A conversation entered the queue of a department the recipient can accept
 * from. Bursts fold into one unread notification per department.
 */
class ConversationPendingNotification extends InAppNotification
{
    /** Conversations remembered by an aggregated notification. */
    public const MAX_TRACKED = 20;

    public function __construct(
        public readonly int $conversationId,
        public readonly ?int $departmentId,
        public readonly int $eventId,
    ) {}

    public function kind(): NotificationKind
    {
        return NotificationKind::ConversationPending;
    }

    public function sourceKey(): string
    {
        return "conversation.pending:{$this->eventId}";
    }

    public function aggregateKey(): string
    {
        return 'conversation.pending:department:'.($this->departmentId ?? 'none');
    }

    public function payload(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'department_id' => $this->departmentId,
            'conversation_ids' => [$this->conversationId],
        ];
    }

    public function aggregate(array $existing): array
    {
        $ids = array_values(array_filter((array) ($existing['conversation_ids'] ?? []), 'is_int'));
        $ids = array_values(array_unique([$this->conversationId, ...$ids]));

        return [
            ...$this->payload(),
            'conversation_ids' => array_slice($ids, 0, self::MAX_TRACKED),
        ];
    }
}
