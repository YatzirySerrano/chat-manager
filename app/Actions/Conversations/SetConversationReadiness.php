<?php

namespace App\Actions\Conversations;

use App\Enums\AuditAction;
use App\Enums\ConversationConflict as ConversationConflictReason;
use App\Enums\ConversationEventType;
use App\Enums\ConversationReadiness;
use App\Enums\ConversationStatus;
use App\Exceptions\ConversationStateConflict;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Conversations\ConversationLifecycle;
use App\Services\Conversations\QueueRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Confirms a request as complete («Lista para atención») or returns it to
 * «Necesita información». Readiness is a preparation state, independent of
 * the conversation status: it never moves the conversation, its agent or
 * its messages, and the customer keeps writing normally.
 *
 * - Allowed on pending and open conversations, with
 *   conversations.confirm-ready over a conversation within the operational
 *   reach; never on closed or imported ones.
 * - Idempotent: asking for the state it already has changes nothing and
 *   records nothing (a retried request succeeds).
 * - Returning it to «Necesita información» requires a reason: a free text
 *   of at least REASON_MIN characters, or the list picked from the catalog
 *   of missing information (`$listed`, any length).
 * - Confirming a request waiting in a queue starts its queue clock
 *   (`queued_at`): the minimum wait and the chronological order count from
 *   the confirmation. Returning it takes it out of the ready queue while the
 *   confirmation rule applies to it. An open conversation (in attention)
 *   goes back to «Pendiente» in its department queue, without agent: while
 *   information is missing nobody attends it and, where the confirmation
 *   rule applies, nobody takes it until it is confirmed complete. The
 *   agent's freed room is offered to their other queues.
 * - «Esperando información del cliente»: whenever a person returns a
 *   request waiting in a queue (or the open one that goes back to it), it
 *   is stamped `awaiting_info_at = now()`. While stamped, the request is out
 *   of «Pendientes» (its own chip, ConversationInboxQuery) and never blocks
 *   the chronological order (it is not eligible). The customer's next
 *   message brings it back by itself (ResumeAwaitingInformation); a person
 *   may also confirm it complete, which clears the stamp and restarts its
 *   queue clock (`queued_at = now()`), as a new request with the same folio.
 * - Recorded as a conversation event and in the audit log, with the
 *   previous and the new state and the reason. Once committed, the queue of
 *   an automatic department is offered again.
 */
class SetConversationReadiness
{
    public const REASON_MIN = 5;

    public function __construct(
        private ConversationLifecycle $lifecycle,
        private QueueRules $queueRules,
        private AssignAutomatically $assignAutomatically,
        private AuditLogger $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Conversation $conversation, ConversationReadiness $target, User $actor, ?string $reason = null, ?int $expectedVersion = null, bool $listed = false, bool $returnToQueue = true): Conversation
    {
        $conversation = $this->change($conversation, $target, $actor, $reason, $expectedVersion, $listed);

        // The caller that still sends the customer the missing list (only
        // possible in attention) returns it afterwards (returnToQueue()).
        return $target === ConversationReadiness::NeedsInfo && $returnToQueue ? $this->returnToQueue($conversation, $actor) : $conversation;
    }

    /**
     * A conversation in attention that needs information goes back to
     * «Pendiente» in its department queue, without agent. Nothing happens
     * when it is not open or no longer needs information.
     */
    public function returnToQueue(Conversation $conversation, User $actor): Conversation
    {
        return DB::transaction(function () use ($conversation, $actor): Conversation {
            $conversation = $this->lifecycle->lock($conversation);

            if ($conversation->readiness !== ConversationReadiness::NeedsInfo || $conversation->status !== ConversationStatus::Open) {
                return $conversation;
            }

            $releasedFrom = $conversation->assigned_user_id;
            $this->lifecycle->lockDepartment($conversation->department_id, exclusive: true);
            // Out of «Pendientes» until the customer writes again or someone
            // confirms it complete (ResumeAwaitingInformation).
            $this->lifecycle->apply($conversation, ConversationStatus::Pending, [
                ...$this->lifecycle->queued($conversation),
                'awaiting_info_at' => now(),
            ]);
            $this->lifecycle->record($conversation, ConversationEventType::Unassigned, $actor, [
                'from_user_id' => $releasedFrom,
                'from_department_id' => $conversation->department_id,
                'note' => 'Necesita información',
            ]);

            if ($releasedFrom !== null) {
                $this->assignAutomatically->distributeForAgentAfterCommit($releasedFrom, [$conversation->id]);
            }

            return $conversation;
        });
    }

    /**
     * @throws ValidationException
     */
    private function change(Conversation $conversation, ConversationReadiness $target, User $actor, ?string $reason, ?int $expectedVersion, bool $listed): Conversation
    {
        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);

        if ($target === ConversationReadiness::NeedsInfo && mb_strlen((string) $reason) < ($listed ? 1 : self::REASON_MIN)) {
            throw ValidationException::withMessages(['reason' => 'Indica qué información falta (al menos '.self::REASON_MIN.' caracteres).']);
        }

        return DB::transaction(function () use ($conversation, $target, $actor, $reason, $expectedVersion): Conversation {
            $conversation = $this->lifecycle->lock($conversation);
            // Permission (403) and state (409: closed) first, without the
            // version: a retried request must find its work done, not a conflict.
            $actor = $this->lifecycle->authorize($actor, 'confirmReadiness', $conversation);

            if ($conversation->readiness === $target) {
                return $conversation;
            }

            if ($expectedVersion !== null && $conversation->version !== $expectedVersion) {
                throw ConversationStateConflict::because(ConversationConflictReason::StateChanged);
            }

            $previous = $conversation->readiness;
            $inQueue = $this->queueRules->inQueue($conversation);
            // Whether the confirmation rule was keeping it out of the ready queue.
            $wasHeld = $this->queueRules->needsInformation($conversation);
            $wasAwaiting = $conversation->awaiting_info_at !== null;

            $conversation->forceFill([
                'readiness' => $target,
                'readiness_changed_at' => now(),
                'readiness_changed_by_user_id' => $actor->id,
                'version' => $conversation->version + 1,
                // Returned by a person while waiting in a queue: it waits for
                // the customer outside «Pendientes». Confirmed: it no longer
                // waits for anything (any state, so no stale stamp survives).
                'awaiting_info_at' => $target === ConversationReadiness::NeedsInfo
                    ? ($inQueue ? now() : $conversation->awaiting_info_at)
                    : null,
            ]);

            if ($inQueue) {
                $conversation->forceFill(['queued_at' => match (true) {
                    // Enters the ready queue now (or had no clock yet).
                    $target === ConversationReadiness::Ready => $wasHeld || $wasAwaiting || $conversation->queued_at === null ? now() : $conversation->queued_at,
                    // Leaves the ready queue only where the rule applies to it.
                    $this->queueRules->needsInformation($conversation) => null,
                    default => $conversation->queued_at,
                }]);
            }

            $conversation->save();

            $confirmed = $target === ConversationReadiness::Ready;

            $this->lifecycle->record($conversation, $confirmed ? ConversationEventType::ReadinessConfirmed : ConversationEventType::ReadinessReverted, $actor, [
                'note' => $reason,
                'metadata' => ['previous' => $previous->value, 'new' => $target->value],
            ]);

            $this->audit->record($confirmed ? AuditAction::ConversationReadinessConfirmed : AuditAction::ConversationReadinessReverted, $conversation, [
                'previous_readiness' => $previous->value,
                'new_readiness' => $target->value,
                'reason' => $reason,
            ], $actor);

            if ($confirmed && $inQueue) {
                $this->assignAutomatically->distributeDepartmentAfterCommit($conversation->department_id);
            }

            return $conversation;
        });
    }
}
