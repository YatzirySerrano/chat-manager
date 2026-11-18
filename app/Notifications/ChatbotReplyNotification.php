<?php

namespace App\Notifications;

/**
 * The chatbot answered a customer automatically because their message had a
 * keyword (ReplyToChatbotKeyword): the agent attending the conversation, or
 * the people who can take it from its queue, learn what the customer was
 * told. Only identifiers travel; the presenter reads the rule's name.
 */
class ChatbotReplyNotification extends InAppNotification
{
    public function __construct(
        public readonly int $conversationId,
        public readonly int $messageId,
        public readonly int $ruleId,
    ) {}

    public function kind(): NotificationKind
    {
        return NotificationKind::ChatbotReply;
    }

    public function sourceKey(): string
    {
        return "message:{$this->messageId}";
    }

    public function payload(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'message_id' => $this->messageId,
            'rule_id' => $this->ruleId,
        ];
    }
}
