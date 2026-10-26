<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Conversations\ConversationLifecycle;
use Illuminate\Support\Facades\DB;

/**
 * Returns a conversation in attention (or on hold) to its department queue.
 * In automatic departments it is offered to another eligible agent, and
 * the room it frees offers the previous responsible the other conversations
 * waiting in their automatic departments (never the one just released).
 * Locks follow the engine order: conversation, department, agent.
 */
class ReleaseConversation
{
    public function __construct(
        private ConversationLifecycle $lifecycle,
        private AssignAutomatically $assignAutomatically,
    ) {}

    public function handle(Conversation $conversation, User $actor, ?string $reason = null, ?int $expectedVersion = null): Conversation
    {
        return DB::transaction(function () use ($conversation, $actor, $reason, $expectedVersion): Conversation {
            $conversation = $this->lifecycle->lock($conversation);
            $actor = $this->lifecycle->authorize($actor, 'release', $conversation, $expectedVersion);
            $previous = $conversation->assigned_user_id;
            // Exclusive before the event's foreign key takes a shared lock on it:
            // distributing the queue below must not upgrade that lock.
            $this->lifecycle->lockDepartment($conversation->department_id, exclusive: true);

            $this->lifecycle->apply($conversation, ConversationStatus::Pending, $this->lifecycle->queued());
            $this->lifecycle->record($conversation, ConversationEventType::Unassigned, $actor, [
                'from_user_id' => $previous,
                'from_department_id' => $conversation->department_id,
                'note' => $reason === null || trim($reason) === '' ? null : trim($reason),
            ]);

            $this->assignAutomatically->handle($conversation, $previous === null ? [] : [$previous]);

            // The previous responsible now has room: offer them the rest of
            // their automatic queues, but not the conversation they just released.
            if ($previous !== null && $conversation->assigned_user_id !== $previous) {
                $this->assignAutomatically->distributeForAgentAfterCommit($previous, [$conversation->id]);
            }

            return $conversation;
        });
    }
}
