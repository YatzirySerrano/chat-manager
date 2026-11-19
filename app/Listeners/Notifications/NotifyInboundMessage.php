<?php

namespace App\Listeners\Notifications;

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Events\Messaging\MessageRecorded;
use App\Models\Conversation;
use App\Models\Message;
use App\Notifications\ConversationMessageNotification;
use App\Notifications\NotificationRecipients;
use App\Services\Chatbot\ChatbotConversations;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * A customer message in a conversation someone attends notifies that agent;
 * in one nobody took yet (any channel, the web chat included), it notifies
 * whoever can take it and the administrators
 * (NotificationRecipients::forUnattendedConversation). The first message of
 * a conversation is announced as «pendiente» instead, never twice.
 * The notification is aggregated per conversation (see
 * ConversationMessageNotification), so a chatty customer produces one unread
 * notification whose counter grows, not one per message. Opening the
 * conversation marks it read; the client also skips sound and browser alerts
 * for the conversation on screen.
 */
class NotifyInboundMessage implements ShouldQueue
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30];

    public function __construct(
        private NotificationRecipients $recipients,
        private ChatbotConversations $chatbot,
    ) {}

    public function viaQueue(): string
    {
        return Queues::name(Queues::NOTIFICATIONS);
    }

    /**
     * Only customer messages notify: outbound ones never queue a job.
     */
    public function shouldQueue(MessageRecorded $event): bool
    {
        return $event->direction === MessageDirection::Inbound->value;
    }

    public function handle(MessageRecorded $event): void
    {
        if ($event->direction !== MessageDirection::Inbound->value) {
            return;
        }

        $conversation = Conversation::query()->find($event->conversationId);

        if ($conversation === null || $conversation->status === ConversationStatus::Closed) {
            return;
        }

        if ($conversation->assigned_user_id !== null) {
            $recipient = $this->recipients->ifCanView($conversation->assigned_user_id, $conversation);

            $recipient?->notify(new ConversationMessageNotification($conversation->id, $event->messageId));

            return;
        }

        // Nobody took it yet (web chat, WhatsApp…): the customer keeps
        // writing, so whoever can take it and the administrators hear it.
        // Not while the chatbot menu is still answering it.
        if ($conversation->status !== ConversationStatus::Pending || $this->chatbot->isAwaiting($conversation->id)) {
            return;
        }

        $earlier = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::Inbound->value)
            ->where('id', '<', $event->messageId)
            ->exists();

        if (! $earlier) {
            return;
        }

        $recipients = $this->recipients->forUnattendedConversation($conversation);

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new ConversationMessageNotification($conversation->id, $event->messageId));
        }
    }
}
