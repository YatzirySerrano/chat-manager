<?php

namespace App\Services\Conversations;

use App\Enums\ConversationConflict as ConversationConflictReason;
use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Events\Conversations\ConversationChanged;
use App\Exceptions\ConversationConflict;
use App\Exceptions\ConversationStateConflict;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\Department;
use App\Models\User;
use App\Policies\ConversationPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Building blocks shared by every operation of the engine. They must run
 * inside a database transaction:
 *
 * - lock() serializes operations on the same conversation (row lock) and
 *   detects decisions taken on stale data through the version counter;
 * - authorize() re-evaluates the actor's permissions with fresh data once
 *   the lock is held, so a permission revoked meanwhile is honored, and
 *   tells a real refusal (403) from a state that changed meanwhile (409);
 * - apply() only performs transitions of the state matrix;
 * - record() appends the operational event.
 *
 * Lock order (the same in every operation, so two of them never wait for
 * each other in a cycle):
 *
 * 1. the conversation row(s), in id order when there are several;
 * 2. the conversation's (or destination's) department row (lockDepartment()):
 *    exclusive while choosing an agent of an automatic department or moving
 *    the conversation to a queue, shared otherwise. It must come before the
 *    user because the event (and a moved conversation) references the
 *    department, and that foreign key check takes a shared lock on its row
 *    anyway: taken after the user, it inverted the order of a distribution
 *    (department, then agent). A new inbound conversation locks its
 *    automatic department before inserting its row, which no other
 *    transaction can hold yet;
 * 3. the user rows (freshUser()), with shared locks on their role and on
 *    their membership rows (department_user), never on department rows;
 * 4. shared reads of the agent's other conversations to count their load
 *    (ConversationAccess::attentionLoad()).
 *
 * Step 4 reads conversation rows after a user row. An operation holding one
 * of those conversations while waiting for the same agent row (e.g. moving
 * the agent's own conversation to another department while keeping them as
 * responsible) can still form a cycle; MariaDB detects it, rolls one of the
 * two transactions back completely and the user receives a controlled
 * "retry" response. Releasing an agent's conversations while holding their
 * row (ReleaseAssignedConversations) is the other documented exception.
 */
class ConversationLifecycle
{
    /** A real authorization failure on a conversation the actor can see (403). */
    public const FORBIDDEN_MESSAGE = 'No tienes autorización para realizar esta acción.';

    /** The permanent refusal of any operation on imported history (403). */
    public const IMPORTED_MESSAGE = 'Esta conversación es un historial importado y es de solo consulta.';

    public function __construct(private ConversationStateGuard $state) {}

    /**
     * The 403 for an operation the actor may not perform on a conversation
     * they can see, explaining when it is imported (read-only) history.
     */
    public static function forbidden(Conversation $conversation): AuthorizationException
    {
        return new AuthorizationException($conversation->isImported() ? self::IMPORTED_MESSAGE : self::FORBIDDEN_MESSAGE);
    }

    /**
     * Permission and reach only (ConversationPolicy::permits()), whatever the
     * state: for checks outside the engine's lock.
     *
     * @throws AuthorizationException
     */
    public function ensurePermitted(User $actor, string $ability, Conversation $conversation): void
    {
        if (! $this->policy()->permits($actor, $ability, $conversation)) {
            throw self::forbidden($conversation);
        }
    }

    public function lock(Conversation $conversation, ?int $expectedVersion = null): Conversation
    {
        $locked = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);

        if ($expectedVersion !== null && $locked->version !== $expectedVersion) {
            throw ConversationStateConflict::because(ConversationConflictReason::StateChanged);
        }

        return $locked;
    }

    /**
     * Step 2 of the lock order: the department row, before any user row.
     * Shared is enough to keep it from changing (and matches the foreign key
     * checks of the event); exclusive when the operation may distribute the
     * department's queue, so the shared lock is never upgraded later.
     */
    public function lockDepartment(?int $departmentId, bool $exclusive = false): ?Department
    {
        if ($departmentId === null) {
            return null;
        }

        $query = Department::query()->whereKey($departmentId);

        return ($exclusive ? $query->lockForUpdate() : $query->sharedLock())->first();
    }

    /**
     * Fresh copy of the actor, authorized for the ability on the locked
     * conversation, in this order:
     *
     * 1. the conversation is still visible to them (the request already
     *    proved it before the lock, so losing it now means it moved
     *    meanwhile: a conflict, never details of where it went);
     * 2. permission and reach of the operation (ConversationPolicy::permits()):
     *    refused, a real authorization failure, 403;
     * 3. the version the user decided on, when sent: stale, a 409 explaining
     *    what changed;
     * 4. the state admits the operation (ConversationStateGuard::ensure()):
     *    409, or 403 when the conversation simply was never the actor's.
     *
     * Accepting has its own visibility rule (queues the actor may take from),
     * checked before by the controller, so step 1 does not apply to it.
     *
     * @throws AuthorizationException
     * @throws ConversationStateConflict
     */
    public function authorize(User $actor, string $ability, Conversation $conversation, ?int $expectedVersion = null): User
    {
        $fresh = $this->freshUser($actor->id, lock: false);
        $gate = Gate::forUser($fresh);

        if ($ability !== 'accept' && $gate->denies('view', $conversation)) {
            throw ConversationStateConflict::because(ConversationConflictReason::StateChanged);
        }

        $this->ensurePermitted($fresh, $ability, $conversation);

        $this->state->ensure($fresh, $ability, $conversation, staleVersion: $expectedVersion !== null && $conversation->version !== $expectedVersion);

        return $fresh;
    }

    private function policy(): ConversationPolicy
    {
        return app(ConversationPolicy::class);
    }

    /**
     * A user re-read (and optionally row locked) with the data that decides
     * whether they can attend: account state, role and departments.
     *
     * When locked, the role and the membership rows are read with shared
     * locks: a locking read sees the latest committed membership (not the
     * transaction snapshot) and a membership or role change in progress waits
     * for this operation, so a decision is never taken on a membership that
     * is being removed. Department rows themselves are not locked here: an
     * automatic distribution holds its department row before the agent row,
     * so locking departments after the user inverted that order (deadlocks
     * between a new conversation and an accept, or between two automatic
     * departments sharing an agent). Whoever depends on the state of a
     * department locks that row itself, before the user.
     */
    public function freshUser(int $userId, bool $lock = true): User
    {
        if (! $lock) {
            return User::query()->with(['roles', 'departments'])->findOrFail($userId);
        }

        $user = User::query()
            ->with(['roles' => fn ($query) => $query->sharedLock()])
            ->lockForUpdate()
            ->findOrFail($userId);

        $departmentIds = DB::table('department_user')
            ->where('user_id', $userId)
            ->orderBy('department_id')
            ->sharedLock()
            ->pluck('department_id')
            ->all();

        $user->setRelation('departments', Department::query()->whereKey($departmentIds)->orderBy('id')->get());

        return $user;
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
