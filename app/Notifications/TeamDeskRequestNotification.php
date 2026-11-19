<?php

namespace App\Notifications;

/**
 * Someone wrote to the «Mesa de control» and the request went to the waiting
 * list: every auditor (except the requester herself) is told, so the first
 * one available accepts it. Bursts fold into one unread notification.
 */
class TeamDeskRequestNotification extends InAppNotification
{
    public function __construct(
        public readonly int $threadId,
        public readonly int $messageId,
        public readonly int $requesterId,
    ) {}

    public function kind(): NotificationKind
    {
        return NotificationKind::TeamChatDeskRequest;
    }

    public function sourceKey(): string
    {
        return "team-chat.desk-request:{$this->messageId}";
    }

    public function aggregateKey(): string
    {
        return $this->kind()->value;
    }

    public function payload(): array
    {
        return [
            'thread_id' => $this->threadId,
            'message_id' => $this->messageId,
            'author_id' => $this->requesterId,
        ];
    }
}
