<?php

namespace App\Services\Conversations;

use App\Enums\ConversationConflict;
use App\Enums\ConversationStatus;
use App\Enums\Permission;
use App\Exceptions\ConversationStateConflict;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The state half of every conversation operation: whether the conversation,
 * as it is now, admits the operation for this actor. Permission and reach
 * (the other half) live in ConversationPolicy::permits(); the engine checks
 * permission first (403) and this guard afterwards, under lock (409), so an
 * agent who lost a race learns what happened instead of reading "forbidden".
 *
 * Being responsible is state: the responsible agent changes when someone
 * accepts, assigns, transfers or releases the conversation, so an agent who
 * acted on their own conversation after it moved learns that another agent
 * attends it (or that it changed), not that they lack permission. Someone
 * for whom the conversation was never theirs is refused as a real 403
 * (ensure()).
 */
class ConversationStateGuard
{
    /**
     * Why the operation cannot run on the conversation as it is now, or
     * null when its state admits it.
     */
    public function conflict(User $actor, string $ability, Conversation $conversation): ?ConversationConflict
    {
        $closed = $conversation->status === ConversationStatus::Closed;

        return match ($ability) {
            'accept' => match (true) {
                $closed => ConversationConflict::Closed,
                $conversation->status === ConversationStatus::Pending && $conversation->assigned_user_id === null => null,
                default => $this->responsibleConflict($actor, $conversation),
            },
            'assign' => $closed ? ConversationConflict::Closed : null,
            'transfer', 'close' => match (true) {
                $closed => ConversationConflict::Closed,
                $this->manages($actor) || $this->isResponsible($actor, $conversation) => null,
                default => $this->responsibleConflict($actor, $conversation),
            },
            'release' => match (true) {
                $closed => ConversationConflict::Closed,
                ! $conversation->status->hasResponsible() => ConversationConflict::StateChanged,
                $this->manages($actor) || $this->isResponsible($actor, $conversation) => null,
                default => $this->responsibleConflict($actor, $conversation),
            },
            'reopen' => $closed ? null : ConversationConflict::StateChanged,
            'reply', 'sendTemplate' => match (true) {
                $closed => ConversationConflict::Closed,
                $conversation->status === ConversationStatus::Open && $this->isResponsible($actor, $conversation) => null,
                default => $this->responsibleConflict($actor, $conversation),
            },
            // Tags classify closed conversations too (with the history reach).
            'tag' => $this->manages($actor) || $this->isResponsible($actor, $conversation)
                ? null
                : $this->responsibleConflict($actor, $conversation),
            default => null,
        };
    }

    public function allows(User $actor, string $ability, Conversation $conversation): bool
    {
        return $this->conflict($actor, $ability, $conversation) === null;
    }

    /**
     * Refuses the operation when the conversation as it is now does not
     * admit it:
     *
     * - decided on a stale version: 409 with the most specific explanation
     *   of what changed since;
     * - not being responsible, for someone who never attended the
     *   conversation (no event moved it away from them) and does not
     *   manage it: nothing changed for them, it simply is not theirs, a real
     *   403;
     * - otherwise, 409 (closed, another agent attends it now, it changed).
     *
     * @throws ConversationStateConflict
     * @throws AuthorizationException
     */
    public function ensure(User $actor, string $ability, Conversation $conversation, bool $staleVersion = false): void
    {
        $conflict = $this->conflict($actor, $ability, $conversation);

        if ($staleVersion) {
            if ($conflict === null && $ability !== 'reopen' && $conversation->status === ConversationStatus::Closed) {
                $conflict = ConversationConflict::Closed;
            }

            throw ConversationStateConflict::because($conflict ?? $this->responsibleConflict($actor, $conversation));
        }

        if ($conflict === null) {
            return;
        }

        if ($this->needsResponsibility($actor, $ability, $conversation) && ! $this->attendedBefore($actor, $conversation)) {
            throw new AuthorizationException(ConversationLifecycle::FORBIDDEN_MESSAGE);
        }

        throw ConversationStateConflict::because($conflict);
    }

    /**
     * The refusal is only about not being the responsible agent (the
     * conversation is not closed and the actor does not manage it).
     */
    private function needsResponsibility(User $actor, string $ability, Conversation $conversation): bool
    {
        if ($conversation->status === ConversationStatus::Closed || $this->isResponsible($actor, $conversation)) {
            return false;
        }

        return match ($ability) {
            'reply', 'sendTemplate' => true,
            'transfer', 'close', 'release', 'tag' => ! $this->manages($actor),
            default => false,
        };
    }

    /**
     * Whether the conversation was ever taken away from the actor (assigned,
     * transferred, released or retired while they attended it).
     */
    private function attendedBefore(User $actor, Conversation $conversation): bool
    {
        return $conversation->events()->where('from_user_id', $actor->id)->exists();
    }

    /**
     * Another agent attends it now, or it simply is not where the user saw
     * it (pending, on hold, back in a queue).
     */
    private function responsibleConflict(User $actor, Conversation $conversation): ConversationConflict
    {
        return $conversation->status->hasResponsible() && $conversation->assigned_user_id !== null && $conversation->assigned_user_id !== $actor->id
            ? ConversationConflict::AssignedToOther
            : ConversationConflict::StateChanged;
    }

    private function isResponsible(User $actor, Conversation $conversation): bool
    {
        return $conversation->status->hasResponsible() && $conversation->assigned_user_id === $actor->id;
    }

    /**
     * Users who assign act on any conversation within their reach, whoever
     * attends it.
     */
    private function manages(User $actor): bool
    {
        return $actor->holdsPermission(Permission::AssignConversations);
    }
}
