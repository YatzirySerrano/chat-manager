<?php

namespace App\Actions\Conversations;

use App\Enums\AuditAction;
use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Conversations\ConversationLifecycle;
use Illuminate\Support\Facades\DB;

/**
 * «No es solicitud»: after a finished request the customer often answers
 * «gracias», an emoji or «ok», and that message starts a new conversation
 * in «Pendientes». This closes it straight away, from the queue (nobody has
 * to accept it first, and the queue rules never block it) or while someone
 * attends it:
 *
 * - nothing is sent to the customer (no farewell: the event is not a
 *   close, so SendAutomaticMessages never reacts to it);
 * - `not_request_at` keeps it outside every metric and report
 *   (ConversationMetricsCalculator removes its rows, the SLA and the
 *   dashboard skip it);
 * - the customer's next message never reopens it, it starts a new
 *   conversation, as after a completed request (ReceiveInboundMessage);
 * - the agent who attended it gets that room back, as with any close.
 *
 * Same lock order as CloseConversation: the conversation, then its
 * department (shared), then the user rows.
 */
class MarkNotRequest
{
    public function __construct(
        private ConversationLifecycle $lifecycle,
        private AssignAutomatically $assignAutomatically,
        private AuditLogger $audit,
    ) {}

    public function handle(Conversation $conversation, User $actor, ?int $expectedVersion = null): Conversation
    {
        return DB::transaction(function () use ($conversation, $actor, $expectedVersion): Conversation {
            $conversation = $this->lifecycle->lock($conversation);
            // Lock order step 2, before the event's foreign keys lock the user rows.
            $this->lifecycle->lockDepartment($conversation->department_id);
            $actor = $this->lifecycle->authorize($actor, 'markNotRequest', $conversation, $expectedVersion);

            $fromStatus = $conversation->status;
            $fromAgentId = $conversation->assigned_user_id;

            $this->lifecycle->apply($conversation, ConversationStatus::Closed, [
                // From the queue it stays with whoever dismissed it, so it is
                // in their «Cerradas» and they can undo it; an attended one
                // keeps its agent, as any close does.
                ...($fromAgentId === null ? ['assigned_user_id' => $actor->id, 'assigned_at' => now()] : []),
                'closed_at' => now(),
                'closed_by_user_id' => $actor->id,
                'completed_at' => null,
                'on_hold_at' => null,
                'on_hold_reason' => null,
                'outcome' => null,
                'outcome_amount' => null,
                'outcome_at' => null,
                'outcome_by_user_id' => null,
                'not_request_at' => now(),
                'not_request_by_user_id' => $actor->id,
            ]);
            $this->lifecycle->record($conversation, ConversationEventType::NotRequest, $actor, [
                'from_user_id' => $fromAgentId,
                'from_department_id' => $conversation->department_id,
                // Where it was, so «Deshacer» puts it back (UndoNotRequest).
                'metadata' => ['from_status' => $fromStatus->value],
            ]);

            $this->audit->record(AuditAction::ConversationMarkedNotRequest, $conversation, [
                'from_status' => $fromStatus->value,
                'department_id' => $conversation->department_id,
                'assigned_user_id' => $fromAgentId,
            ], $actor);

            // The agent who attended it has room for one more.
            $this->assignAutomatically->distributeForAgentAfterCommit($fromAgentId);

            return $conversation;
        });
    }
}
