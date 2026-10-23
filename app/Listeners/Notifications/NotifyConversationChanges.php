<?php

namespace App\Listeners\Notifications;

use App\Broadcasting\RealtimeSignals;
use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Events\Conversations\ConversationChanged;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\User;
use App\Notifications\ConversationAssignedNotification;
use App\Notifications\ConversationPendingNotification;
use App\Notifications\ConversationTransferredNotification;
use App\Notifications\NotificationCenter;
use App\Notifications\NotificationRecipients;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Turns conversation events into notifications, reading the current state
 * (the conversation may have moved on since the event):
 *
 * - entering the queue (created, released, transferred to a department,
 *   reopened without agent) notifies the available users who can accept it,
 *   unless it was already taken;
 * - assignments (manual, automatic, return on reopen) notify the new
 *   responsible agent, never the person who did it themselves;
 * - a direct transfer notifies the receiving agent;
 * - the agent who lost the conversation (transfer, release, reassignment)
 *   gets its pending notifications marked read when they can no longer see
 *   it, so their bell does not keep counting news they cannot open (a
 *   `.notification` hint makes their open tabs refresh the bell at once);
 * - reading a conversation marks its notifications read for that user.
 */
class NotifyConversationChanges implements ShouldQueue
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30];

    public function __construct(
        private NotificationRecipients $recipients,
        private NotificationCenter $center,
        private RealtimeSignals $signals,
    ) {}

    public function viaQueue(): string
    {
        return Queues::name(Queues::NOTIFICATIONS);
    }

    /**
     * Lifecycle events that can produce a notification (see handle()).
     *
     * @var list<ConversationEventType>
     */
    private const NOTIFYING_EVENTS = [
        ConversationEventType::Created,
        ConversationEventType::Unassigned,
        ConversationEventType::Transferred,
        ConversationEventType::Reopened,
        ConversationEventType::Assigned,
        ConversationEventType::AutoAssigned,
    ];

    /**
     * Messages, tags and the other changes that never notify do not queue
     * a job (handle() keeps the same filter as a safeguard).
     */
    public function shouldQueue(ConversationChanged $event): bool
    {
        if ($event->reason === 'read') {
            return true;
        }

        return $event->eventId !== null
            && in_array(ConversationEventType::tryFrom($event->reason), self::NOTIFYING_EVENTS, true);
    }

    public function handle(ConversationChanged $event): void
    {
        if ($event->reason === 'read') {
            $viewer = $event->actorId === null ? null : User::query()->find($event->actorId);

            if ($viewer !== null) {
                $this->center->markConversationRead($viewer, $event->conversationId);
            }

            return;
        }

        $type = ConversationEventType::tryFrom($event->reason);

        if ($type === null || $event->eventId === null) {
            return;
        }

        $record = ConversationEvent::query()->find($event->eventId);
        $conversation = Conversation::query()->find($event->conversationId);

        if ($record === null || $conversation === null) {
            return;
        }

        $this->forgetForPreviousAgent($conversation, $record);

        match ($type) {
            ConversationEventType::Created,
            ConversationEventType::Unassigned => $this->notifyQueue($conversation, $record),
            ConversationEventType::Transferred => $record->to_user_id === null
                ? $this->notifyQueue($conversation, $record)
                : $this->notifyTransfer($conversation, $record),
            ConversationEventType::Reopened => $record->to_user_id === null
                ? $this->notifyQueue($conversation, $record)
                : $this->notifyAssignment($conversation, $record, 'reopened'),
            ConversationEventType::Assigned => $this->notifyAssignment($conversation, $record, 'assigned'),
            ConversationEventType::AutoAssigned => $this->notifyAssignment($conversation, $record, 'auto_assigned'),
            default => null,
        };
    }

    /**
     * The conversation left its previous agent: if they lost access to it,
     * its notifications (aggregated messages, assignment, transfer) are no
     * longer news for them. If they still see it (a supervisor of the
     * department, a global scope) nothing is touched.
     *
     * Marking them read is a plain update, so their open tabs get the same
     * `.notification` hint a new notification sends (without id: there is
     * nothing new to alert about): the bell reloads and the counter drops
     * without a page reload. It is not a NotificationSignal, which would
     * also wake Web Push.
     */
    private function forgetForPreviousAgent(Conversation $conversation, ConversationEvent $record): void
    {
        $previousId = $record->from_user_id;

        if ($previousId === null || $previousId === $conversation->assigned_user_id) {
            return;
        }

        if ($this->recipients->ifCanView($previousId, $conversation) !== null) {
            return;
        }

        $previous = User::query()->find($previousId);

        if ($previous !== null && $this->center->markConversationRead($previous, $conversation->id) > 0) {
            $this->signals->toUsers([$previous->id], 'notification', [
                'id' => null,
                'kind' => null,
                'reason' => 'access_lost',
            ]);
        }
    }

    private function notifyQueue(Conversation $conversation, ConversationEvent $record): void
    {
        if ($conversation->status !== ConversationStatus::Pending || $conversation->assigned_user_id !== null) {
            return;
        }

        $recipients = $this->recipients->forPendingConversation($conversation, $record->actor_user_id);

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new ConversationPendingNotification($conversation->id, $conversation->department_id, $record->id));
        }
    }

    private function notifyAssignment(Conversation $conversation, ConversationEvent $record, string $reason): void
    {
        $recipient = $this->responsible($conversation, $record);

        if ($recipient !== null) {
            $recipient->notify(new ConversationAssignedNotification($conversation->id, $record->id, $record->actor_user_id, $reason));
        }
    }

    private function notifyTransfer(Conversation $conversation, ConversationEvent $record): void
    {
        $recipient = $this->responsible($conversation, $record);

        if ($recipient !== null) {
            $recipient->notify(new ConversationTransferredNotification($conversation->id, $record->id, $record->actor_user_id));
        }
    }

    /**
     * The agent who received the conversation, if they still hold it, did not
     * do it themselves and can see it.
     */
    private function responsible(Conversation $conversation, ConversationEvent $record): ?User
    {
        if ($record->to_user_id === null
            || $record->to_user_id === $record->actor_user_id
            || $conversation->assigned_user_id !== $record->to_user_id) {
            return null;
        }

        return $this->recipients->ifCanView($record->to_user_id, $conversation);
    }
}
