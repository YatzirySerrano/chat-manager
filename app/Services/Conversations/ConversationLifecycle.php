<?php

namespace App\Services\Conversations;

use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Events\Conversations\ConversationChanged;
use App\Exceptions\ConversationConflict;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Building blocks shared by every operation of the engine. They must run
 * inside a database transaction:
 *
 * - lock() serializes operations on the same conversation (row lock) and
 *   detects decisions taken on stale data through the version counter;
 * - authorize() re-evaluates the actor's permissions with fresh data once
 *   the lock is held, so a permission revoked meanwhile is honored;
 * - apply() only performs transitions of the state matrix;
 * - record() appends the operational event.
 *
 * Lock order is always conversation first, then users, then departments.
 */
class ConversationLifecycle
{
    public function lock(Conversation $conversation, ?int $expectedVersion = null): Conversation
    {
        $locked = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);

        if ($expectedVersion !== null && $locked->version !== $expectedVersion) {
            throw ConversationConflict::stale();
        }

        return $locked;
    }

    /**
     * Fresh copy of the actor, authorized for the ability on the locked conversation.
     */
    public function authorize(User $actor, string $ability, Conversation $conversation): User
    {
        $fresh = $this->freshUser($actor->id, lock: false);

        Gate::forUser($fresh)->authorize($ability, $conversation);

        return $fresh;
    }

    /**
     * A user re-read (and optionally row locked) with the data that decides
     * whether they can attend: account state, role and departments.
     *
     * When locked, role and departments are read with shared locks: a locking
     * read sees the latest committed membership (not the transaction snapshot)
     * and a membership or role change in progress waits for this operation,
     * so a decision is never taken on a membership that is being removed.
     */
    public function freshUser(int $userId, bool $lock = true): User
    {
        if (! $lock) {
            return User::query()->with(['roles', 'departments'])->findOrFail($userId);
        }

        return User::query()
            ->with([
                'roles' => fn ($query) => $query->sharedLock(),
                'departments' => fn ($query) => $query->sharedLock(),
            ])
            ->lockForUpdate()
            ->findOrFail($userId);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function apply(Conversation $conversation, ConversationStatus $target, array $attributes = []): void
    {
        if (! $conversation->status->canTransitionTo($target)) {
            throw ConversationConflict::transition($conversation->status->label(), $target->label());
        }

        $conversation->forceFill([
            ...$attributes,
            'status' => $target,
            'version' => $conversation->version + 1,
        ])->save();
    }

    /**
     * Attributes of a conversation that now has the given agent responsible.
     *
     * @return array<string, mixed>
     */
    public function attendedBy(User $agent): array
    {
        return [
            'assigned_user_id' => $agent->id,
            'assigned_at' => now(),
            'on_hold_at' => null,
            'on_hold_reason' => null,
        ];
    }

    /**
     * Attributes of a conversation waiting in its queue.
     *
     * @return array<string, mixed>
     */
    public function queued(): array
    {
        return [
            'assigned_user_id' => null,
            'assigned_at' => null,
            'on_hold_at' => null,
            'on_hold_reason' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function record(Conversation $conversation, ConversationEventType $type, ?User $actor, array $attributes = []): ConversationEvent
    {
        $event = $conversation->events()->create([
            ...$attributes,
            'type' => $type,
            'actor_user_id' => $actor?->id,
            'created_at' => now(),
        ]);

        ConversationChanged::dispatch($conversation->id, $type->value, $actor?->id, $event->id);

        return $event;
    }

    /**
     * Remember the moment the agent last received a conversation, so the
     * automatic distribution rotates between equally loaded agents.
     */
    public function touchAssignment(User $agent): void
    {
        $agent->forceFill(['last_assigned_at' => now()])->saveQuietly();
    }
}
