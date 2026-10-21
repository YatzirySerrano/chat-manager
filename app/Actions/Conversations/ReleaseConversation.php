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
 * In automatic departments it is offered to another available agent.
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
            $conversation = $this->lifecycle->lock($conversation, $expectedVersion);
            $actor = $this->lifecycle->authorize($actor, 'release', $conversation);
            $previous = $conversation->assigned_user_id;

            $this->lifecycle->apply($conversation, ConversationStatus::Pending, $this->lifecycle->queued());
            $this->lifecycle->record($conversation, ConversationEventType::Unassigned, $actor, [
                'from_user_id' => $previous,
                'from_department_id' => $conversation->department_id,
                'note' => $reason === null || trim($reason) === '' ? null : trim($reason),
            ]);

            $this->assignAutomatically->handle($conversation, $previous === null ? [] : [$previous]);

            return $conversation;
        });
    }
}
