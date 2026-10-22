<?php

namespace App\Notifications;

/**
 * A conversation became the recipient's responsibility: assigned by someone,
 * by the automatic distribution, or returned to them when it reopened.
 */
class ConversationAssignedNotification extends InAppNotification
{
    public function __construct(
        public readonly int $conversationId,
        public readonly int $eventId,
        public readonly ?int $actorId,
        public readonly string $reason,
    ) {}

    public function kind(): NotificationKind
    {
        return NotificationKind::ConversationAssigned;
    }

    public function sourceKey(): string
    {
        return "conversation.event:{$this->eventId}";
    }

    public function payload(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'event_id' => $this->eventId,
            'actor_id' => $this->actorId,
            'reason' => $this->reason,
        ];
    }
}
