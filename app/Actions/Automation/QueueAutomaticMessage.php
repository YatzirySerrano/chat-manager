<?php

namespace App\Actions\Automation;

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Events\Messaging\MessageRecorded;
use App\Jobs\DeliverOutboundMessage;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Services\Automation\AutomaticMessageKind;
use App\Services\Automation\AutomaticMessagePlan;
use App\Services\BusinessHours;
use App\Services\Conversations\ConversationLifecycle;
use App\Services\Conversations\CustomerServiceWindow;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

/**
 * Queues an automatic text message (welcome, away or farewell) to the
 * customer, with no human sender. Guarantees:
 *
 * - idempotency: its client reference is derived from (conversation, slot,
 *   cycle), and `messages.client_reference` is unique, so duplicated
 *   webhooks, re-dispatched listeners or racing workers store it once; the
 *   welcome and away messages share the greeting slot, so a cycle never gets
 *   both;
 * - Meta rules: only free-form text inside the open customer service window
 *   (never a template, never outside the window) and only through an
 *   operational connection; the delivery job checks both again;
 * - no loops: it is only triggered by conversation lifecycle events or by a
 *   customer message (the away message in open conversations), never by
 *   outbound or automatic messages, so none of them triggers another one;
 * - away message once per period outside business hours, when the
 *   connection's administrator turned on «También en conversaciones
 *   abiertas» (`away_in_open_conversations`): a customer writing again in
 *   an open or queued conversation gets it once per continuous period
 *   outside hours (handleAwayInOpenConversation(), referenced by
 *   (conversation, period)), and the greeting of a new cycle skips it when
 *   that period already got one. A period is identified by the next
 *   opening of the conversation's schedule, which every moment of the same
 *   closed stretch shares. Both paths check it under the conversation lock,
 *   so they never both send it. With the switch off, nothing changes: one
 *   greeting per cycle, as before;
 * - traceability: metadata.automatic = true, automatic_kind, the source of
 *   the text, the cycle and the triggering conversation event (or customer
 *   message and period).
 *
 * It does not set first_response_at nor last_outbound_at: an automatic
 * message is not an answer from the team, and the conversation keeps
 * waiting for one.
 */
class QueueAutomaticMessage
{
    /** Namespace of the deterministic (UUID v5) client references. */
    public const REFERENCE_NAMESPACE = '6f0c1f7e-3b9a-5d2c-9e41-7a1b2c3d4e5f';

    public function __construct(
        private ConversationLifecycle $lifecycle,
        private CustomerServiceWindow $window,
        private BusinessHours $hours,
    ) {}

    public static function reference(int $conversationId, AutomaticMessageKind $kind, int $cycle): string
    {
        return Uuid::uuid5(self::REFERENCE_NAMESPACE, "conversation:{$conversationId}:{$kind->slot()}:{$cycle}")->toString();
    }

    /**
     * Reference of the away message of an open conversation in one period
     * outside business hours.
     */
    public static function awayPeriodReference(int $conversationId, string $period): string
    {
        return Uuid::uuid5(self::REFERENCE_NAMESPACE, "conversation:{$conversationId}:away-period:{$period}")->toString();
    }

    /**
     * Key of the period outside business hours that contains the moment:
     * the next opening of the schedule, shared by every moment of the same
     * closed stretch ("never" when the schedule has no opening ahead).
     */
    public function periodKey(Conversation $conversation, CarbonInterface $at): string
    {
        return $this->hours->nextOpening($this->operatingDepartment($conversation), $at)?->toIso8601String() ?? 'never';
    }

    /**
     * The away message of an open (or queued) conversation whose customer
     * wrote again outside business hours, once per period. Returns null when
     * nothing was sent (already sent in this period, conversation closed
     * meanwhile, window closed, connection not operational).
     */
    public function handleAwayInOpenConversation(Conversation $conversation, AutomaticMessagePlan $plan, string $period, int $triggerMessageId): ?Message
    {
        try {
            return DB::transaction(function () use ($conversation, $plan, $period, $triggerMessageId): ?Message {
                $conversation = $this->lifecycle->lock($conversation);
                $reference = self::awayPeriodReference($conversation->id, $period);

                if (Message::query()->where('client_reference', $reference)->exists()) {
                    return null;
                }

                if ($conversation->status === ConversationStatus::Closed) {
                    return $this->skipped($conversation, $plan, 'conversation_state_changed');
                }

                if ($this->awaySentInPeriodOf($conversation, now())) {
                    return $this->skipped($conversation, $plan, 'already_sent_in_period');
                }

                return $this->deliver($conversation, $plan, $reference, [
                    'cycle' => $conversation->reopen_count,
                    'away_period' => $period,
                    'trigger_message_id' => $triggerMessageId,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Another worker stored this period's message first.
            return null;
        }
    }

    /**
     * Returns the queued message, or null when nothing was sent (already sent
     * in this cycle, window closed, connection not operational, conversation
     * no longer in the expected state).
     */
    public function handle(Conversation $conversation, AutomaticMessagePlan $plan, int $cycle, ?int $triggerEventId = null): ?Message
    {
        try {
            return DB::transaction(fn (): ?Message => $this->queue($conversation, $plan, $cycle, $triggerEventId));
        } catch (UniqueConstraintViolationException) {
            // Another worker stored this cycle's message first.
            return null;
        }
    }

    private function queue(Conversation $conversation, AutomaticMessagePlan $plan, int $cycle, ?int $triggerEventId): ?Message
    {
        $conversation = $this->lifecycle->lock($conversation);
        $reference = self::reference($conversation->id, $plan->kind, $cycle);

        // Already sent in this cycle (repeated webhook or event): nothing to record.
        if ($conversation->reopen_count !== $cycle || Message::query()->where('client_reference', $reference)->exists()) {
            return null;
        }

        $closed = $conversation->status === ConversationStatus::Closed;

        if ($plan->kind === AutomaticMessageKind::Farewell ? ! $closed : $closed) {
            return $this->skipped($conversation, $plan, 'conversation_state_changed');
        }

        // With away messages once per period, a new cycle does not repeat the
        // one this period already got (from the previous cycle or from the
        // open conversation path).
        if ($plan->kind === AutomaticMessageKind::Away
            && $conversation->channelConnection()->value('away_in_open_conversations')
            && $this->awaySentInPeriodOf($conversation, now())) {
            return $this->skipped($conversation, $plan, 'already_sent_in_period');
        }

        return $this->deliver($conversation, $plan, $reference, [
            'cycle' => $cycle,
            'trigger_event_id' => $triggerEventId,
        ]);
    }

    /**
     * Stores the message and hands it to the delivery job, when the
     * connection can send and the customer service window is open. Must run
     * inside the transaction holding the conversation lock.
     *
     * @param  array<string, int|string|null>  $trace
     */
    private function deliver(Conversation $conversation, AutomaticMessagePlan $plan, string $reference, array $trace): ?Message
    {
        $connection = $conversation->channelConnection()->first();

        if ($connection === null || ! $connection->isOperational()) {
            return $this->skipped($conversation, $plan, 'connection_not_operational');
        }

        if (! $this->window->for($conversation)->allowsFreeFormMessages()) {
            return $this->skipped($conversation, $plan, 'customer_window_closed');
        }

        $message = new Message([
            'conversation_id' => $conversation->id,
            'client_reference' => $reference,
            'direction' => MessageDirection::Outbound,
            'type' => MessageType::Text,
            'status' => MessageStatus::Queued,
            'body' => $plan->body,
            'sender_user_id' => null,
            'metadata' => [
                'automatic' => true,
                'automatic_kind' => $plan->kind->value,
                'automatic_source' => $plan->source,
                ...$trace,
            ],
        ]);
        $message->save();

        if ($conversation->last_message_at === null || $conversation->last_message_at->lessThan(now())) {
            $conversation->forceFill(['last_message_at' => now()])->save();
        }

        DeliverOutboundMessage::dispatch($message->id)->afterCommit();
        MessageRecorded::dispatch($message->id, $conversation->id, MessageDirection::Outbound->value);

        return $message;
    }

    /**
     * Whether the conversation already got an away message during the
     * period outside business hours that contains the moment: its latest
     * away message was stored while closed and shares the period's next
     * opening.
     */
    private function awaySentInPeriodOf(Conversation $conversation, CarbonInterface $at): bool
    {
        $latest = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('metadata->automatic', true)
            ->where('metadata->automatic_kind', AutomaticMessageKind::Away->value)
            ->latest('id')
            ->first(['id', 'created_at']);

        if ($latest?->created_at === null) {
            return false;
        }

        $department = $this->operatingDepartment($conversation);

        if ($this->hours->isOpenAt($department, $latest->created_at)) {
            return false;
        }

        return $this->periodKey($conversation, $latest->created_at) === $this->periodKey($conversation, $at);
    }

    /**
     * The conversation's department while it operates (a deleted or
     * inactive one no longer sets the schedule), as the greeting uses it.
     */
    private function operatingDepartment(Conversation $conversation): ?Department
    {
        $department = $conversation->department_id === null ? null : $conversation->department()->first();

        return $department !== null && $department->is_active && ! $department->isDeleted() ? $department : null;
    }

    /**
     * Logs why the message was not queued (no customer data, no text).
     */
    private function skipped(Conversation $conversation, AutomaticMessagePlan $plan, string $reason): null
    {
        Log::info('Automatic message not sent.', [
            'conversation_id' => $conversation->id,
            'connection_id' => $conversation->connection_id,
            'kind' => $plan->kind->value,
            'reason' => $reason,
        ]);

        return null;
    }
}
