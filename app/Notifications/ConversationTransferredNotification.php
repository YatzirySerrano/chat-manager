<?php

namespace App\Notifications;

/**
 * Someone transferred a conversation directly to the recipient. The reason
 * written by the colleague is not copied: it is read from the conversation
 * history by whoever can see it.
 */
class ConversationTransferredNotification extends InAppNotification
{
    public function __construct(
        public readonly int $conversationId,
        public readonly int $eventId,
        public readonly ?int $actorId,
    ) {}

    public function kind(): NotificationKind
    {
        return NotificationKind::ConversationTransferred;
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
        ];
    }
}
