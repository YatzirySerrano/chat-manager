<?php

namespace App\Actions\Conversations;

use App\Enums\AuditAction;
use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Enums\DistributionMode;
use App\Exceptions\ConversationConflict;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Conversations\ConversationAccess;
use App\Services\Conversations\ConversationLifecycle;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * «Deshacer» of «No es solicitud» (MarkNotRequest), offered for a few
 * minutes right after marking it (WINDOW_MINUTES). The conversation goes
 * back where it was: to the agent who attended it when they can still
 * attend it, otherwise to «Pendientes» keeping its queue clock
 * (`queued_at` untouched), so it does not lose its turn. Nothing is sent to
 * the customer and it counts again in the metrics. Unlike «Reabrir» it is
 * not a reopening (no reopen count, no automatic messages).
 *
 * Same lock order as ReopenConversation: the conversation, its department
 * (exclusive when its queue may be distributed), then the agent row.
 */
class UndoNotRequest
{
    /** Minutes «Deshacer» stays available after marking «No es solicitud». */
    public const WINDOW_MINUTES = 10;

    public function __construct(
        private ConversationAccess $access,
        private ConversationLifecycle $lifecycle,
        private AssignAutomatically $assignAutomatically,
        private AuditLogger $audit,
    ) {}

    public function handle(Conversation $conversation, User $actor, ?int $expectedVersion = null): Conversation
    {
        try {
            return DB::transaction(function () use ($conversation, $actor, $expectedVersion): Conversation {
                $conversation = $this->lifecycle->lock($conversation);
                $automatic = $conversation->department_id !== null
                    && Department::query()->whereKey($conversation->department_id)->where('distribution_mode', DistributionMode::Automatic)->exists();
                $department = $this->lifecycle->lockDepartment($conversation->department_id, exclusive: $automatic);
                $actor = $this->lifecycle->authorize($actor, 'undoNotRequest', $conversation, $expectedVersion);

                // Dismissed from the queue, it was only kept by whoever dismissed it: back to the queue.
                $fromQueue = $this->markedFromQueue($conversation);
                $previousAgent = $fromQueue || $conversation->assigned_user_id === null ? null : $this->lifecycle->freshUser($conversation->assigned_user_id);
                $returnsToAgent = $previousAgent !== null && $this->access->canAttend($previousAgent, $department);

                $this->lifecycle->apply(
                    $conversation,
                    $returnsToAgent ? ConversationStatus::Open : ConversationStatus::Pending,
                    [
                        // Back to its agent as it was, or to its queue with the same clock.
                        ...($returnsToAgent ? [] : $this->lifecycle->queued()),
                        'closed_at' => null,
                        'closed_by_user_id' => null,
                        'not_request_at' => null,
                        'not_request_by_user_id' => null,
                    ],
                );

                $this->lifecycle->record($conversation, ConversationEventType::NotRequestUndone, $actor, [
                    'to_user_id' => $returnsToAgent ? $previousAgent->id : null,
                    'to_department_id' => $conversation->department_id,
                ]);

                $this->audit->record(AuditAction::ConversationNotRequestUndone, $conversation, [
                    'status' => $conversation->status->value,
                    'department_id' => $conversation->department_id,
                    'assigned_user_id' => $conversation->assigned_user_id,
                ], $actor);

                if (! $returnsToAgent) {
                    $this->assignAutomatically->handle($conversation);
                }

                return $conversation;
            });
        } catch (UniqueConstraintViolationException) {
            // The customer wrote again meanwhile: that new conversation is the live one.
            throw ConversationConflict::anotherActive();
        }
    }

    /**
     * Whether it was waiting in its queue when it was marked (the latest
     * «No es solicitud» event says where it was).
     */
    private function markedFromQueue(Conversation $conversation): bool
    {
        $event = $conversation->events()
            ->where('type', ConversationEventType::NotRequest)
            ->latest('id')
            ->first(['id', 'metadata']);

        return ($event?->metadata['from_status'] ?? ConversationStatus::Pending->value) === ConversationStatus::Pending->value;
    }
}
