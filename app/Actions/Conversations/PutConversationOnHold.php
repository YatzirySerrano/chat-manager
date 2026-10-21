<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Conversations\ConversationLifecycle;
use Illuminate\Support\Facades\DB;

/**
 * Pauses the attention of a conversation that cannot be solved right now.
 * It keeps its agent; waiting does not affect the customer service window.
 */
class PutConversationOnHold
{
    public function __construct(private ConversationLifecycle $lifecycle) {}

    public function handle(Conversation $conversation, User $actor, ?string $reason = null, ?int $expectedVersion = null): Conversation
    {
        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);

        return DB::transaction(function () use ($conversation, $actor, $reason, $expectedVersion): Conversation {
            $conversation = $this->lifecycle->lock($conversation, $expectedVersion);
            $actor = $this->lifecycle->authorize($actor, 'hold', $conversation);

            $this->lifecycle->apply($conversation, ConversationStatus::OnHold, [
                'on_hold_at' => now(),
                'on_hold_reason' => $reason,
            ]);
            $this->lifecycle->record($conversation, ConversationEventType::PutOnHold, $actor, [
                'to_user_id' => $conversation->assigned_user_id,
                'note' => $reason,
            ]);

            return $conversation;
        });
    }
}
