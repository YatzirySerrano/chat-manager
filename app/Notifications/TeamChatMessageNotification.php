<?php

namespace App\Notifications;

/**
 * A message of the internal team chat, either mentioning the recipient
 * (urgent) or simply posted in one of their threads. Messages of the same
 * thread fold into one unread notification per kind.
 */
class TeamChatMessageNotification extends InAppNotification
{
    public function __construct(
        public readonly int $threadId,
        public readonly int $messageId,
        public readonly ?int $authorId,
        public readonly bool $mentioned,
    ) {}

    public function kind(): NotificationKind
    {
        return $this->mentioned ? NotificationKind::TeamChatMention : NotificationKind::TeamChatMessage;
    }

    public function sourceKey(): string
    {
        return "team-chat.message:{$this->messageId}";
    }

    public function aggregateKey(): string
    {
        return $this->kind()->value.":thread:{$this->threadId}";
    }

    public function payload(): array
    {
        return [
            'thread_id' => $this->threadId,
            'message_id' => $this->messageId,
            'author_id' => $this->authorId,
        ];
    }
}
