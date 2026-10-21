<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\User;

/**
 * Returns the non-closed conversations of an agent to their department queue,
 * so no conversation stays assigned to someone who can no longer attend it.
 */
class ReleaseAssignedConversations
{
    /**
     * @return int Number of released conversations.
     */
    public function handle(User $agent, ?int $departmentId = null, ?User $actor = null, string $reason = ''): int
    {
        $released = 0;

        Conversation::query()
            ->where('assigned_user_id', $agent->id)
            ->notClosed()
            ->when($departmentId !== null, fn ($query) => $query->where('department_id', $departmentId))
            ->lockForUpdate()
            ->get()
            ->each(function (Conversation $conversation) use ($agent, $actor, $reason, &$released): void {
                $conversation->forceFill([
                    'assigned_user_id' => null,
                    'assigned_at' => null,
                    'on_hold_at' => null,
                    'on_hold_reason' => null,
                    'status' => ConversationStatus::Pending,
                    'version' => $conversation->version + 1,
                ])->save();

                $conversation->events()->create([
                    'type' => ConversationEventType::Unassigned,
                    'actor_user_id' => $actor?->id,
                    'from_user_id' => $agent->id,
                    'note' => $reason !== '' ? $reason : null,
                    'created_at' => now(),
                ]);

                $released++;
            });

        return $released;
    }
}
