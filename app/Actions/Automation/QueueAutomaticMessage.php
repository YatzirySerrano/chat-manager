<?php

namespace App\Actions\Automation;

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Events\Messaging\MessageRecorded;
use App\Jobs\DeliverOutboundMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Automation\AutomaticMessageKind;
use App\Services\Automation\AutomaticMessagePlan;
use App\Services\Conversations\ConversationLifecycle;
use App\Services\Conversations\CustomerServiceWindow;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
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
 * - no loops: it is only triggered by conversation lifecycle events, never by
 *   messages, so automatic or outbound messages never trigger another one;
 * - traceability: metadata.automatic = true, automatic_kind, the source of
 *   the text, the cycle and the triggering conversation event.
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
    ) {}

    public static function reference(int $conversationId, AutomaticMessageKind $kind, int $cycle): string
    {
        return Uuid::uuid5(self::REFERENCE_NAMESPACE, "conversation:{$conversationId}:{$kind->slot()}:{$cycle}")->toString();
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

        if ($conversation->reopen_count !== $cycle || Message::query()->where('client_reference', $reference)->exists()) {
            return null;
        }

        $closed = $conversation->status === ConversationStatus::Closed;

        if ($plan->kind === AutomaticMessageKind::Farewell ? ! $closed : $closed) {
            return null;
        }

        $connection = $conversation->channelConnection()->first();

        if ($connection === null || ! $connection->isOperational() || ! $this->window->for($conversation)->allowsFreeFormMessages()) {
            return null;
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
                'cycle' => $cycle,
                'trigger_event_id' => $triggerEventId,
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
}
