<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Conversations\ConversationLifecycle;
use Illuminate\Support\Facades\DB;

/**
 * Ends the attention. The agent who attended it is kept for history, and
 * nothing is sent to the customer. Once committed, the agent has room for
 * one more conversation, so the queues of their automatic departments are
 * offered again.
 */
class CloseConversation
{
    public function __construct(
        private ConversationLifecycle $lifecycle,
        private AssignAutomatically $assignAutomatically,
    ) {}

    public function handle(Conversation $conversation, User $actor, ?string $reason = null, ?int $expectedVersion = null): Conversation
    {
        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);

        return DB::transaction(function () use ($conversation, $actor, $reason, $expectedVersion): Conversation {
            $conversation = $this->lifecycle->lock($conversation, $expectedVersion);
            $actor = $this->lifecycle->authorize($actor, 'close', $conversation);

            $this->lifecycle->apply($conversation, ConversationStatus::Closed, [
                'closed_at' => now(),
                'closed_by_user_id' => $actor->id,
                'on_hold_at' => null,
                'on_hold_reason' => null,
            ]);
            $this->lifecycle->record($conversation, ConversationEventType::Closed, $actor, [
                'from_user_id' => $conversation->assigned_user_id,
                'from_department_id' => $conversation->department_id,
                'note' => $reason,
            ]);

            $this->assignAutomatically->distributeForAgentAfterCommit($conversation->assigned_user_id);

            return $conversation;
        });
    }
}
