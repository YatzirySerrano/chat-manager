<?php

namespace App\Listeners\Notifications;

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Events\Messaging\MessageRecorded;
use App\Models\Conversation;
use App\Notifications\ConversationMessageNotification;
use App\Notifications\NotificationRecipients;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A customer message in a conversation someone attends notifies that agent.
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

    public function __construct(private NotificationRecipients $recipients) {}

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

        $recipient = $this->recipients->ifCanView($conversation->assigned_user_id, $conversation);

        $recipient?->notify(new ConversationMessageNotification($conversation->id, $event->messageId));
    }
}
