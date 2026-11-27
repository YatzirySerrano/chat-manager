<?php

namespace App\Actions\Conversations;

use App\Enums\AuditAction;
use App\Enums\ConversationEventType;
use App\Enums\ConversationReadiness;
use App\Models\Conversation;
use App\Services\AuditLogger;
use App\Services\Conversations\ConversationLifecycle;
use App\Services\Conversations\QueueRules;

/**
 * «Esperando información del cliente» ends by itself when the customer
 * writes again: the request someone returned for missing information comes
 * back to «Pendientes» as if it had just arrived, in the same conversation
 * and with the same folio (never a new conversation):
 *
 * - readiness «Lista para atención», `awaiting_info_at` cleared;
 * - a new queue clock (`queued_at = now()`): the minimum wait and the
 *   chronological order count from this message, so it never jumps ahead of
 *   the requests that arrived while it was waiting;
 * - still «Pendiente» and without agent (it only applies to a request that
 *   waits in a queue);
 * - a timeline event (ReadinessConfirmed without actor, trigger
 *   `customer_info`) and an audit entry by «Sistema»;
 * - once committed, the queue of an automatic department is offered again.
 *
 * Only for requests stamped as waiting (`awaiting_info_at`), which only a
 * person returning the request sets (SetConversationReadiness): a request
 * born «Necesita información» under the confirmation rule keeps waiting for
 * a person to confirm it, as before.
 *
 * Called by ReceiveInboundMessage inside its transaction, with the
 * conversation row already locked (FOR UPDATE) by the receipt and after the
 * message is stored, so the event follows the message in the timeline.
 */
class ResumeAwaitingInformation
{
    /** Metadata trigger of the timeline event (TimelineEvent.vue). */
    public const TRIGGER = 'customer_info';

    public const NOTE = 'El cliente envió más información; la solicitud regresó a Pendientes.';

    public function __construct(
        private ConversationLifecycle $lifecycle,
        private QueueRules $queueRules,
        private AssignAutomatically $assignAutomatically,
        private AuditLogger $audit,
    ) {}

    /**
     * Whether the conversation (locked by the caller) waits for the
     * customer's missing information.
     */
    public static function applies(Conversation $conversation): bool
    {
        return $conversation->awaiting_info_at !== null
            && $conversation->readiness === ConversationReadiness::NeedsInfo;
    }

    /**
     * What the customer was asked for, for the notice of the open
     * conversation: the reason of the latest «Necesita información» event,
     * one entry per item of the list (MissingInformation::reason() joins them
     * with «; »), or the free text as a single entry. Empty when the request
     * does not wait for the customer or no reason was recorded.
     *
     * @return list<string>
     */
    public static function missing(Conversation $conversation): array
    {
        if (! self::applies($conversation)) {
            return [];
        }

        $note = $conversation->events()
            ->where('type', ConversationEventType::ReadinessReverted->value)
            ->latest('id')
            ->value('note');

        if (! is_string($note) || trim($note) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode('; ', $note)), fn (string $item): bool => $item !== ''));
    }

    /**
     * Brings the request back to «Pendientes»; false (nothing changed) when
     * it was not waiting for the customer.
     */
    public function handle(Conversation $conversation): bool
    {
        if (! self::applies($conversation) || ! $this->queueRules->inQueue($conversation)) {
            return false;
        }

        $conversation->forceFill([
            'readiness' => ConversationReadiness::Ready,
            'readiness_changed_at' => now(),
            // Nobody confirmed it: the customer's message did.
            'readiness_changed_by_user_id' => null,
            'awaiting_info_at' => null,
            'queued_at' => now(),
            'version' => $conversation->version + 1,
        ])->save();

        $this->lifecycle->record($conversation, ConversationEventType::ReadinessConfirmed, null, [
            'note' => self::NOTE,
            'metadata' => [
                'previous' => ConversationReadiness::NeedsInfo->value,
                'new' => ConversationReadiness::Ready->value,
                'trigger' => self::TRIGGER,
            ],
        ]);

        $this->audit->record(AuditAction::ConversationReadinessConfirmed, $conversation, [
            'previous_readiness' => ConversationReadiness::NeedsInfo->value,
            'new_readiness' => ConversationReadiness::Ready->value,
            'reason' => self::NOTE,
        ]);

        $this->assignAutomatically->distributeDepartmentAfterCommit($conversation->department_id);

        return true;
    }
}
