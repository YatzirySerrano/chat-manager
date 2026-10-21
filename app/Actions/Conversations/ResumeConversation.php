<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Conversations\ConversationLifecycle;
use Illuminate\Support\Facades\DB;

/**
 * Takes a conversation on hold back into attention with the same agent.
 */
class ResumeConversation
{
    public function __construct(private ConversationLifecycle $lifecycle) {}

    public function handle(Conversation $conversation, User $actor, ?int $expectedVersion = null): Conversation
    {
        return DB::transaction(function () use ($conversation, $actor, $expectedVersion): Conversation {
            $conversation = $this->lifecycle->lock($conversation, $expectedVersion);
            $actor = $this->lifecycle->authorize($actor, 'resume', $conversation);
            $heldSince = $conversation->on_hold_at;

            $this->lifecycle->apply($conversation, ConversationStatus::Open, [
                'on_hold_at' => null,
                'on_hold_reason' => null,
            ]);
            $this->lifecycle->record($conversation, ConversationEventType::Resumed, $actor, [
                'to_user_id' => $conversation->assigned_user_id,
                'metadata' => ['held_since' => $heldSince?->toIso8601ZuluString()],
            ]);

            return $conversation;
        });
    }
}
