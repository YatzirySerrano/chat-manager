<?php

namespace App\Listeners\Automation;

use App\Actions\Automation\QueueAutomaticMessage;
use App\Enums\ConversationEventType;
use App\Events\Conversations\ConversationChanged;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\Department;
use App\Services\Automation\AutomaticMessages;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Sends the automatic messages of a conversation cycle:
 *
 * - greeting (welcome or away) when the customer starts a cycle: a new
 *   conversation created by an inbound message, or one reopened by an
 *   inbound message. Conversations created or reopened by people (manual
 *   reopen, imports) get nothing;
 * - farewell when the conversation closes (by an agent or by inactivity).
 *
 * A greeting is skipped when the customer's last message is older than
 * MAX_GREETING_DELAY_MINUTES (a backlog processed late must not greet
 * someone hours later). See QueueAutomaticMessage for the other guarantees.
 */
class SendAutomaticMessages implements ShouldQueue
{
    public const MAX_GREETING_DELAY_MINUTES = 60;

    /**
     * Events that start (greeting) or end (farewell) a conversation cycle.
     *
     * @var list<ConversationEventType>
     */
    private const CYCLE_EVENTS = [
        ConversationEventType::Created,
        ConversationEventType::Reopened,
        ConversationEventType::Closed,
    ];

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30];

    public function __construct(
        private AutomaticMessages $messages,
        private QueueAutomaticMessage $queueMessage,
    ) {}

    public function viaQueue(): string
    {
        return Queues::name(Queues::MESSAGES);
    }

    /**
     * Only the events that start or end a cycle queue a job on the critical
     * "messages" queue; every other change (messages, tags, reads...) does not.
     */
    public function shouldQueue(ConversationChanged $event): bool
    {
        return $event->eventId !== null
            && in_array(ConversationEventType::tryFrom($event->reason), self::CYCLE_EVENTS, true);
    }

    public function handle(ConversationChanged $event): void
    {
        $type = ConversationEventType::tryFrom($event->reason);

        if ($type === null || ! $this->shouldQueue($event)) {
            return;
        }

        $record = ConversationEvent::query()->find($event->eventId);
        $conversation = Conversation::query()->with(['channelConnection', 'department'])->find($event->conversationId);
        $connection = $conversation?->channelConnection;

        if ($record === null || $conversation === null || $connection === null) {
            return;
        }

        if ($type === ConversationEventType::Closed) {
            $plan = $this->messages->farewell($connection);
        } else {
            if (! $this->startedByCustomer($type, $record) || ! $this->isRecent($conversation)) {
                return;
            }

            $plan = $this->messages->greeting($connection, $this->operatingDepartment($conversation->department), now());
        }

        if ($plan !== null) {
            $this->queueMessage->handle($conversation, $plan, $conversation->reopen_count, $record->id);
        }
    }

    private function startedByCustomer(ConversationEventType $type, ConversationEvent $record): bool
    {
        $metadata = $record->metadata ?? [];

        return $type === ConversationEventType::Created
            ? ($metadata['source'] ?? null) === 'inbound'
            : ($metadata['trigger'] ?? null) === 'inbound';
    }

    private function isRecent(Conversation $conversation): bool
    {
        return $conversation->last_inbound_at !== null
            && $conversation->last_inbound_at->greaterThanOrEqualTo(now()->subMinutes(self::MAX_GREETING_DELAY_MINUTES));
    }

    /**
     * Messages of a deleted or inactive department no longer apply.
     */
    private function operatingDepartment(?Department $department): ?Department
    {
        return $department !== null && $department->is_active && ! $department->isDeleted() ? $department : null;
    }
}
