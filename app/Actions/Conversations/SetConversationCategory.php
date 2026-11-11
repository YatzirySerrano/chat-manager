<?php

namespace App\Actions\Conversations;

use App\Enums\AuditAction;
use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Enums\Permission;
use App\Models\Conversation;
use App\Models\ConversationCategory;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Conversations\ConversationLifecycle;
use App\Services\Conversations\QueueRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sets or clears the request category of a conversation (its type). Only
 * active categories can be chosen; a deleted one it already carries stays
 * as history until it is changed. The conversation row is locked and the
 * actor authorized again under lock.
 *
 * The category may carry its own service rules (ServiceRules): they apply
 * to the decisions taken from now on (the next accept, the next response
 * cycle) and never rewrite what was recorded. It does not change the
 * status, the version, the readiness or the queue clock. Recorded as a
 * conversation event (previous and new category), which also signals the
 * inboxes in real time, and in the audit log.
 *
 * A category is also a way of changing the queue rules of one request: on
 * a request waiting in a queue, a category that no longer requires
 * confirmation or that has a shorter minimum wait would let it be taken
 * sooner. That is an exception to the queue rules like any other, so it
 * requires conversations.override-queue-rules (403 without it), read from
 * the actor row locked after the conversation (the engine's lock order:
 * conversation, then user; no department row is locked here).
 */
class SetConversationCategory
{
    public const RELAXES_QUEUE_RULES_MESSAGE = 'Cambiar a esta categoría adelantaría la solicitud en la cola. Necesitas el permiso para hacer excepciones a las reglas de la cola.';

    public function __construct(
        private ConversationLifecycle $lifecycle,
        private AssignAutomatically $assignAutomatically,
        private QueueRules $queueRules,
        private AuditLogger $audit,
    ) {}

    /**
     * @return bool Whether the category changed.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Conversation $conversation, ?int $categoryId, User $actor): bool
    {
        return DB::transaction(function () use ($conversation, $categoryId, $actor): bool {
            $conversation = $this->lifecycle->lock($conversation);
            $actor = $this->lifecycle->authorize($actor, 'categorize', $conversation);

            if ($conversation->category_id === $categoryId) {
                return false;
            }

            $category = $categoryId === null ? null : ConversationCategory::query()->find($categoryId);

            if ($categoryId !== null && $category === null) {
                throw ValidationException::withMessages(['category_id' => 'La categoría elegida no existe.']);
            }

            if ($category !== null && $category->isDeleted()) {
                throw ValidationException::withMessages(['category_id' => "La categoría «{$category->name}» está eliminada y no puede asignarse."]);
            }

            $previous = $conversation->category_id === null ? null : ConversationCategory::query()->find($conversation->category_id);

            $inQueue = $this->queueRules->inQueue($conversation);
            $before = $this->queueRules->rulesFor($conversation);
            $wasEligible = $this->queueRules->isEligible($conversation);

            $conversation->forceFill(['category_id' => $category?->id]);

            $after = $this->queueRules->rulesFor($conversation);
            $relaxed = $inQueue && (($before->confirmationRequired() && ! $after->confirmationRequired())
                || $after->minWaitSeconds() < $before->minWaitSeconds());

            if ($relaxed && ! $this->lifecycle->freshUser($actor->id)->holdsPermission(Permission::OverrideQueueRules)) {
                throw new AuthorizationException(self::RELAXES_QUEUE_RULES_MESSAGE);
            }

            $conversation->save();

            $this->lifecycle->record($conversation, ConversationEventType::CategoryChanged, $actor, [
                'metadata' => [
                    'from_category_id' => $previous?->id,
                    'from_category' => $previous?->name,
                    'to_category_id' => $category?->id,
                    'to_category' => $category?->name,
                ],
            ]);

            $this->audit->record(AuditAction::ConversationCategoryChanged, $conversation, [
                'previous_category_id' => $previous?->id,
                'previous_category' => $previous?->name,
                'new_category_id' => $category?->id,
                'new_category' => $category?->name,
                'queue_rules_relaxed' => $relaxed,
                'queue_eligibility_changed' => $wasEligible !== $this->queueRules->isEligible($conversation),
            ], $actor);

            // Its rules may have changed (a shorter wait, no confirmation):
            // an automatic department offers its queue again.
            if ($conversation->status === ConversationStatus::Pending && $conversation->assigned_user_id === null) {
                $this->assignAutomatically->distributeDepartmentAfterCommit($conversation->department_id);
            }

            return true;
        });
    }
}
