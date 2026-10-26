<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Enums\DistributionMode;
use App\Exceptions\ConversationConflict;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use App\Services\Conversations\ConversationAccess;
use App\Services\Conversations\ConversationLifecycle;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Reopens a closed conversation, by hand or because the customer wrote again
 * within the connection's reopening window. It returns to the agent who
 * attended it when that agent can still attend it; otherwise it waits in its
 * department queue. Reopening never touches the customer service window:
 * only a new customer message opens it again.
 */
class ReopenConversation
{
    public function __construct(
        private ConversationAccess $access,
        private ConversationLifecycle $lifecycle,
        private AssignAutomatically $assignAutomatically,
    ) {}

    public function handle(Conversation $conversation, User $actor, ?int $expectedVersion = null): Conversation
    {
        try {
            return DB::transaction(function () use ($conversation, $actor, $expectedVersion): Conversation {
                $conversation = $this->lifecycle->lock($conversation);
                $actor = $this->lifecycle->authorize($actor, 'reopen', $conversation, $expectedVersion);

                return $this->reopen($conversation, $actor, 'manual');
            });
        } catch (UniqueConstraintViolationException) {
            throw ConversationConflict::anotherActive();
        }
    }

    /**
     * Must run inside a transaction with the conversation locked. A unique
     * violation means the contact already has another unclosed conversation
     * on the same connection.
     *
     * @param  'manual'|'inbound'  $trigger
     */
    public function reopen(Conversation $conversation, ?User $actor, string $trigger): Conversation
    {
        // Engine lock order: the department before the agent; exclusive when
        // its queue may be distributed below, so that lock is never upgraded.
        $automatic = $conversation->department_id !== null
            && Department::query()->whereKey($conversation->department_id)->where('distribution_mode', DistributionMode::Automatic)->exists();
        $department = $this->lifecycle->lockDepartment($conversation->department_id, exclusive: $automatic);
        $previousAgent = $conversation->assigned_user_id === null ? null : $this->lifecycle->freshUser($conversation->assigned_user_id);
        $returnsToAgent = $previousAgent !== null && $this->access->canAttend($previousAgent, $department);

        $this->lifecycle->apply(
            $conversation,
            $returnsToAgent ? ConversationStatus::Open : ConversationStatus::Pending,
            [
                ...($returnsToAgent ? $this->lifecycle->attendedBy($previousAgent) : $this->lifecycle->queued()),
                'closed_at' => null,
                'closed_by_user_id' => null,
                'reopened_at' => now(),
                'reopen_count' => $conversation->reopen_count + 1,
            ],
        );

        $this->lifecycle->record($conversation, ConversationEventType::Reopened, $actor, [
            'to_user_id' => $returnsToAgent ? $previousAgent->id : null,
            'to_department_id' => $conversation->department_id,
            'metadata' => ['trigger' => $trigger],
        ]);

        if (! $returnsToAgent) {
            $this->assignAutomatically->handle($conversation);
        }

        return $conversation;
    }
}
