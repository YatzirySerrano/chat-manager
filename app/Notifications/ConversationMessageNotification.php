<?php

namespace App\Notifications;

/**
 * The customer wrote in a conversation the recipient attends. Messages of
 * the same conversation fold into one unread notification (its occurrences
 * count them) until the recipient reads it or opens the conversation.
 */
class ConversationMessageNotification extends InAppNotification
{
    public function __construct(
        public readonly int $conversationId,
        public readonly int $messageId,
    ) {}

    public function kind(): NotificationKind
    {
        return NotificationKind::ConversationMessage;
    }

    public function sourceKey(): string
    {
        return "message:{$this->messageId}";
    }

    public function aggregateKey(): string
    {
        return "conversation.messages:{$this->conversationId}";
    }

    public function payload(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'message_id' => $this->messageId,
        ];
    }
}
