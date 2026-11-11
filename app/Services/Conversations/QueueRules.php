<?php

namespace App\Services\Conversations;

use App\Enums\AuditAction;
use App\Enums\ConversationReadiness;
use App\Enums\ConversationStatus;
use App\Enums\Permission;
use App\Exceptions\QueueRuleConflict;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ResolvedRules;
use App\Services\ServiceRules;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The three rules that decide whether a request may be taken from its queue,
 * in one place. They are separate rules and are never merged:
 *
 * 1. completeness (`needs_info`): where intake.confirmation_required is on,
 *    a request marked «Necesita información» cannot be taken until someone
 *    confirms it as complete;
 * 2. minimum wait (`min_wait`): a request cannot be taken before it spent
 *    queue.min_wait_minutes in the ready queue;
 * 3. chronological order (`queue_order`): where queue.chronological_order is
 *    on, only the first eligible request of the department may be taken.
 *
 * The capacity limit and the control of pending conversations are agent
 * rules with their own mechanisms (ConversationAccess, PendingAttention);
 * they are evaluated after these and can never be overridden here.
 *
 * One clock: `queued_at`, the moment the request entered the ready queue
 * (creation when it is born ready, confirmation as complete, and every time
 * it goes back to a queue). It drives both the minimum wait and the order.
 * A row without it (born «Necesita información» under a rule that was later
 * turned off, or older data) falls back to `created_at`.
 *
 * Comparable requests (rule 3): pending, unassigned, live, of the same
 * department (no department is its own queue), complete or not requiring
 * confirmation, with their minimum wait elapsed, ordered by clock and id.
 * Requests of other departments, incomplete, still waiting or already taken
 * never block anyone.
 *
 * Rules are read when the decision is taken (ServiceRules): a configuration
 * change applies to the next decision and never rewrites a conversation.
 *
 * @phpstan-type Acceptance array{allowed: bool, available: bool, code: string|null, message: string|null, available_at: string|null, blocking_conversation_id: int|null, overridable: bool}
 * @phpstan-type QueueOverride array{rules: list<string>, reason: string}
 */
class QueueRules
{
    public const NEEDS_INFO = 'needs_info';

    public const MIN_WAIT = 'min_wait';

    public const QUEUE_ORDER = 'queue_order';

    /** Agent-level reasons of the `acceptance` contract (never 409 codes of their own). */
    public const CAPACITY = 'capacity';

    public const PENDING_ATTENTION = 'pending_attention';

    public const NOT_PERMITTED = 'not_permitted';

    public const NEEDS_INFO_MESSAGE = 'Esta solicitud todavía necesita información. Podrá tomarse cuando se confirme como completa.';

    public const QUEUE_ORDER_MESSAGE = 'Existe una solicitud anterior que debe atenderse primero.';

    /**
     * Eligible rows of a queue read at most when looking for an earlier
     * eligible request (per branch of the lookup, see eligible()). The read
     * only returns rows that are eligible (eligibleQuery()), so rows that
     * are incomplete or still waiting never use it up; it only bounds how
     * many candidates are read again under lock.
     */
    public const HEAD_SCAN = 50;

    /** Shortest justification of an exception to the rules. */
    public const OVERRIDE_REASON_MIN = 10;

    /** SQL of the queue clock (clock()): the order of every queue read. */
    public const CLOCK = 'COALESCE(queued_at, created_at)';

    /** Columns the rules read from a queue row. */
    private const COLUMNS = ['id', 'department_id', 'category_id', 'status', 'assigned_user_id', 'origin', 'history_only_at', 'readiness', 'queued_at', 'created_at'];

    /**
     * What a page of rows shares while it is being described (describing()):
     * the head of each department's queue and each viewer's own limits.
     *
     * @var array{heads: array<int, Conversation|null>, agents: array<int, array{load: int, pending: bool}>, departments: array<int, Department|null>, visible: array<string, bool>}|null
     */
    private static ?array $page = null;

    public function __construct(
        private ConversationAccess $access,
        private PendingAttention $pendingAttention,
        private AuditLogger $audit,
    ) {}

    /**
     * The rules in force for the conversation, optionally as if it were in
     * another department (a transfer's destination). ServiceRules is scoped,
     * so it is resolved on every use and never kept.
     */
    public function rulesFor(Conversation $conversation, int|false|null $departmentId = false): ResolvedRules
    {
        return app(ServiceRules::class)->forIds($departmentId === false ? $conversation->department_id : $departmentId, $conversation->category_id);
    }

    /**
     * How a new conversation of the department is born: «Necesita
     * información» and outside the ready queue only where the confirmation
     * rule is on at that moment; ready and queued otherwise.
     *
     * @return array{readiness: ConversationReadiness, queued_at: CarbonInterface|null}
     */
    public function born(?int $departmentId): array
    {
        $needsInfo = app(ServiceRules::class)->forIds($departmentId, null)->confirmationRequired();

        return [
            'readiness' => $needsInfo ? ConversationReadiness::NeedsInfo : ConversationReadiness::Ready,
            'queued_at' => $needsInfo ? null : now(),
        ];
    }

    /**
     * The clock of a conversation that (re)enters a queue: now when it
     * enters as ready, none while it still needs information there.
     *
     * @return array{queued_at: CarbonInterface|null}
     */
    public function entering(Conversation $conversation, int|false|null $departmentId = false): array
    {
        return ['queued_at' => $this->needsInformation($conversation, $departmentId) ? null : now()];
    }

    /**
     * Rule 1: the request is marked «Necesita información» and the
     * confirmation rule is on for it right now.
     */
    public function needsInformation(Conversation $conversation, int|false|null $departmentId = false): bool
    {
        return $conversation->readiness === ConversationReadiness::NeedsInfo
            && $this->rulesFor($conversation, $departmentId)->confirmationRequired();
    }

    /**
     * Since when the request waits in the ready queue.
     */
    public function clock(Conversation $conversation): ?CarbonImmutable
    {
        $clock = $conversation->queued_at ?? $conversation->created_at;

        return $clock === null ? null : CarbonImmutable::instance($clock);
    }

    /**
     * Rule 2: the moment the minimum wait of the request ends, or null when
     * it has none.
     */
    public function availableAt(Conversation $conversation): ?CarbonImmutable
    {
        $wait = $this->rulesFor($conversation)->minWaitSeconds();

        return $wait > 0 ? $this->clock($conversation)?->addSeconds($wait) : null;
    }

    public function isWaiting(Conversation $conversation, ?CarbonInterface $now = null): bool
    {
        $availableAt = $this->availableAt($conversation);

        return $availableAt !== null && $availableAt->greaterThan($now ?? now());
    }

    /**
     * Whether the conversation waits in a queue (pending, unassigned, live).
     */
    public function inQueue(Conversation $conversation): bool
    {
        return $conversation->status === ConversationStatus::Pending
            && $conversation->assigned_user_id === null
            && ! $conversation->isImported()
            && ! $conversation->isHistoryOnly();
    }

    /**
     * Whether the request can be taken as far as rules 1 and 2 go: in its
     * queue, complete and with its minimum wait elapsed. It is what the
     * automatic distribution requires and what makes a request comparable
     * for the chronological order.
     */
    public function isEligible(Conversation $conversation, ?CarbonInterface $now = null): bool
    {
        return $this->inQueue($conversation)
            && ! $this->needsInformation($conversation)
            && ! $this->isWaiting($conversation, $now);
    }

    /**
     * The rules that forbid taking the conversation right now, in their
     * validation order (completeness, minimum wait, chronological order).
     * The caller holds the conversation row locked.
     *
     * `$taking` is false when someone who distributes work hands the request
     * to another person: only completeness applies then (minimum wait and
     * order restrain picking for oneself, not deliberate distribution).
     *
     * With `$lockingRead`, the earlier request that would block this one is
     * read again under a shared row lock (earlierEligible()). It must be
     * called before the department and agent locks of the engine: the
     * earlier rows are conversation rows, the first step of the lock order.
     *
     * @return list<QueueBlock>
     */
    public function violations(Conversation $conversation, bool $taking = true, bool $lockingRead = false): array
    {
        $now = CarbonImmutable::now();
        $violations = [];

        if ($this->needsInformation($conversation)) {
            $violations[] = new QueueBlock(self::NEEDS_INFO, self::NEEDS_INFO_MESSAGE);
        }

        if (! $taking) {
            return $violations;
        }

        $availableAt = $this->availableAt($conversation);

        if ($availableAt !== null && $availableAt->greaterThan($now)) {
            $violations[] = new QueueBlock(self::MIN_WAIT, self::minWaitMessage($availableAt, $now), availableAt: $availableAt);
        }

        if ($this->rulesFor($conversation)->chronologicalOrder()) {
            // Only a refusal decided by the order needs the locking read.
            $earlier = $this->earlierEligible($conversation, $now, $lockingRead && $violations === []);

            if ($earlier !== null) {
                $violations[] = new QueueBlock(self::QUEUE_ORDER, self::QUEUE_ORDER_MESSAGE, blockingConversationId: $earlier->id);
            }
        }

        return $violations;
    }

    /**
     * Refuses the operation with the first violated rule, unless the actor
     * holds conversations.override-queue-rules and justified an exception:
     * then the bypassed rules and the reason are returned to be recorded
     * (recordOverride()). Without the permission the reason is ignored, so no
     * role name or alternative route skips a rule.
     *
     * @param  list<QueueBlock>  $violations
     * @return QueueOverride|null
     *
     * @throws QueueRuleConflict
     * @throws ValidationException
     */
    public function enforce(array $violations, User $actor, ?string $overrideReason): ?array
    {
        if ($violations === []) {
            return null;
        }

        $mayOverride = $actor->holdsPermission(Permission::OverrideQueueRules);
        $reason = $overrideReason === null ? '' : trim($overrideReason);

        if (! $mayOverride || $reason === '') {
            throw new QueueRuleConflict($violations[0], $mayOverride);
        }

        if (mb_strlen($reason) < self::OVERRIDE_REASON_MIN) {
            throw ValidationException::withMessages(['override_reason' => 'Explica el motivo de la excepción con al menos '.self::OVERRIDE_REASON_MIN.' caracteres.']);
        }

        return [
            'rules' => array_map(fn (QueueBlock $block): string => $block->code, $violations),
            'reason' => $reason,
        ];
    }

    /**
     * Audit entry of an exception to the rules: which were bypassed, why, by
     * whom (the entry's actor) and what it resulted in. The conversation
     * event carries the same data in its metadata (`queue_override`).
     *
     * @param  QueueOverride  $override
     * @param  'accepted'|'assigned'|'transferred'  $result
     */
    public function recordOverride(Conversation $conversation, User $actor, array $override, string $result, ?User $agent = null): void
    {
        $this->audit->record(AuditAction::ConversationQueueRulesOverridden, $conversation, [
            'bypassed_rules' => $override['rules'],
            'reason' => $override['reason'],
            'override_result' => $result,
            'assigned_to' => $agent?->name,
        ], $actor);
    }

    /**
     * «Podrás tomarla dentro de 3 minutos.»
     */
    public static function minWaitMessage(CarbonInterface $availableAt, ?CarbonInterface $now = null): string
    {
        $seconds = max(1, (int) ceil(($now ?? now())->diffInSeconds($availableAt)));

        if ($seconds < 60) {
            return 'Podrás tomarla dentro de '.($seconds === 1 ? '1 segundo' : "{$seconds} segundos").'.';
        }

        $minutes = (int) ceil($seconds / 60);

        return 'Podrás tomarla dentro de '.($minutes === 1 ? '1 minuto' : "{$minutes} minutos").'.';
    }

    /**
     * The first eligible request that entered the same queue before the
     * conversation, or null when there is none.
     *
     * Concurrency. The candidates come from an ordinary (snapshot) read of
     * the queue index; with `$lock`, each candidate is then read again by
     * primary key under a shared row lock, oldest first, and judged on that
     * fresh row. So when another transaction is taking or changing the
     * earlier request, this one waits for it and decides on the outcome:
     * committed, the earlier request is no longer eligible and stops
     * blocking; rolled back, it still blocks. Two agents can therefore
     * never both skip the head of a queue unnoticed, and whoever takes the
     * second request while the head is being accepted is allowed in.
     *
     * No cycle is possible: a transaction holding one queue row only ever
     * waits for rows that precede it in the queue order, and it takes the
     * department and agent locks afterwards. Primary key lookups take record
     * locks only (no gap locks on the queue index), so they never collide
     * with the index entry a status change moves.
     *
     * A request cannot become "earlier and eligible" behind the snapshot:
     * every path that puts a request in the ready queue stamps `queued_at`
     * with the current time, which is later than the clock of a request
     * already waiting.
     */
    public function earlierEligible(Conversation $conversation, ?CarbonInterface $now = null, bool $lock = false): ?Conversation
    {
        $now ??= CarbonImmutable::now();

        $candidates = $this->eligible($conversation->department_id, self::HEAD_SCAN, $conversation, now: $now)
            ->filter(fn (Conversation $candidate): bool => $this->isEligible($candidate, $now) && $this->precedes($candidate, $conversation));

        foreach ($candidates as $candidate) {
            if (! $lock) {
                return $candidate;
            }

            $fresh = Conversation::query()->whereKey($candidate->id)->sharedLock()->first(self::COLUMNS);

            if ($fresh !== null && $this->isEligible($fresh, $now) && $this->precedes($fresh, $conversation)) {
                return $fresh;
            }
        }

        return null;
    }

    /**
     * The first eligible request of a department's queue (null department:
     * the queue of conversations without one), or null when none is.
     */
    public function head(?int $departmentId, ?CarbonInterface $now = null): ?Conversation
    {
        $now ??= CarbonImmutable::now();

        return $this->eligible($departmentId, self::HEAD_SCAN, now: $now)
            ->first(fn (Conversation $candidate): bool => $this->isEligible($candidate, $now));
    }

    /**
     * The requests of a department's queue (null department: the queue of
     * conversations without one) that are eligible at `$now`, with the
     * static part of isEligible() expressed in SQL: pending, unassigned,
     * live, complete where confirmation is required and with the minimum
     * wait elapsed. The predicate is built from ServiceRules: the value of
     * the department (or the platform) for every row, except the rows of
     * the few categories whose own rules resolve differently there, which
     * get their own branch.
     *
     * It is the single definition every read of "what can be taken" starts
     * from (accepting, the head shown by the inbox, the automatic
     * distribution and the scheduled command), so they never disagree and
     * a backlog of requests that are not eligible never hides one that is.
     * No ordering and no limit: see eligible() and hasEligible().
     *
     * @return Builder<Conversation>
     */
    public function eligibleQuery(?int $departmentId, ?CarbonInterface $now = null): Builder
    {
        $now = CarbonImmutable::instance($now ?? now());
        $rules = app(ServiceRules::class);
        $base = $rules->forIds($departmentId, null);
        $signature = fn (ResolvedRules $resolved): string => ($resolved->confirmationRequired() ? '1' : '0').':'.$resolved->minWaitSeconds();

        /** @var array<string, array{rules: ResolvedRules, ids: list<int>}> $groups */
        $groups = [];

        foreach ($rules->overridingCategoryIds() as $categoryId) {
            $resolved = $rules->forIds($departmentId, $categoryId);

            if ($signature($resolved) !== $signature($base)) {
                $groups[$signature($resolved)]['rules'] = $resolved;
                $groups[$signature($resolved)]['ids'][] = $categoryId;
            }
        }

        $differing = array_merge([], ...array_column($groups, 'ids'));

        $static = function (Builder $query, ResolvedRules $resolved) use ($now): void {
            if ($resolved->confirmationRequired()) {
                $query->where('readiness', '!=', ConversationReadiness::NeedsInfo->value);
            }

            if ($resolved->minWaitSeconds() > 0) {
                $query->whereRaw(self::CLOCK.' <= ?', [$now->subSeconds($resolved->minWaitSeconds())]);
            }
        };

        return Conversation::query()
            ->when($departmentId === null, fn (Builder $query) => $query->whereNull('department_id'), fn (Builder $query) => $query->where('department_id', $departmentId))
            ->where('status', ConversationStatus::Pending)
            ->whereNull('assigned_user_id')
            ->live()
            ->notHistoryOnly()
            ->where(function (Builder $eligible) use ($base, $groups, $differing, $static): void {
                $eligible->where(function (Builder $inherited) use ($base, $differing, $static): void {
                    if ($differing !== []) {
                        $inherited->where(fn (Builder $category) => $category->whereNull('category_id')->orWhereNotIn('category_id', $differing));
                    }

                    $static($inherited, $base);
                });

                foreach ($groups as $group) {
                    $eligible->orWhere(function (Builder $own) use ($group, $static): void {
                        $own->whereIn('category_id', $group['ids']);
                        $static($own, $group['rules']);
                    });
                }
            });
    }

    /**
     * Whether the queue of the department has any eligible request right
     * now: one indexed EXISTS.
     */
    public function hasEligible(?int $departmentId, ?CarbonInterface $now = null): bool
    {
        return $this->eligibleQuery($departmentId, $now)->exists();
    }

    /**
     * The first `$limit` eligible requests of a queue in queue order: their
     * clock (`queued_at`, else `created_at`), then their id. With `$before`,
     * only the ones that precede that conversation; `$exceptIds` are left
     * out.
     *
     * Two bounded branches in one statement, so the common one keeps
     * walking the queue index (department_id, status, queued_at) in order
     * and stops at the limit: the rows with a clock, and the rows without
     * one (ordered by their creation). Both are merged here by position.
     *
     * @param  list<int>  $exceptIds
     * @return Collection<int, Conversation>
     */
    public function eligible(?int $departmentId, int $limit, ?Conversation $before = null, array $exceptIds = [], ?CarbonInterface $now = null): Collection
    {
        $now ??= CarbonImmutable::now();
        $clock = $before === null ? null : $this->clock($before);

        if ($before !== null) {
            $exceptIds[] = (int) $before->id;
        }

        $branch = fn (string $column): Builder => $this->eligibleQuery($departmentId, $now)
            ->select(self::COLUMNS)
            ->when($exceptIds !== [], fn (Builder $query) => $query->whereKeyNot($exceptIds))
            ->when($column === 'queued_at', fn (Builder $query) => $query->whereNotNull('queued_at'), fn (Builder $query) => $query->whereNull('queued_at'))
            ->when($before !== null && $clock !== null, fn (Builder $query) => $query->where(fn (Builder $earlier) => $earlier
                ->where($column, '<', $clock)
                ->orWhere(fn (Builder $same) => $same->where($column, $clock)->where('id', '<', $before->id))))
            ->orderBy($column)
            ->orderBy('id')
            ->limit($limit);

        return $branch('queued_at')->unionAll($branch('created_at'))->get()
            ->sortBy(fn (Conversation $candidate): array => $this->position($candidate))
            ->take($limit)
            ->values();
    }

    /**
     * Runs the callback (resolving a page of rows) sharing what
     * acceptance() needs: one "head of the queue" lookup per department on
     * the page and one read of the viewer's load, instead of one per row.
     * Only for the duration of the callback.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function describing(Closure $callback): mixed
    {
        if (self::$page !== null) {
            return $callback();
        }

        self::$page = ['heads' => [], 'agents' => [], 'departments' => [], 'visible' => []];

        try {
            return $callback();
        } finally {
            self::$page = null;
        }
    }

    /**
     * Whether the viewer can take the pending request right now and, if not,
     * why: the request's own reason first (rules 1 to 3, in order), then the
     * viewer's (no permission over that queue, capacity limit, pending
     * conversations). Null for conversations that are not waiting in a queue.
     *
     * `available` only speaks of the request (rules 1 to 3), whoever looks
     * at it. The engine decides again, under lock, on every accept.
     *
     * @return Acceptance|null
     */
    public function acceptance(Conversation $conversation, ?User $viewer, bool $canAccept): ?array
    {
        if (! $this->inQueue($conversation)) {
            return null;
        }

        $now = CarbonImmutable::now();
        $block = $this->describedBlock($conversation, $now);

        if ($block !== null) {
            return [
                'allowed' => false,
                'available' => false,
                'code' => $block->code,
                'message' => $block->message,
                'available_at' => $block->availableAt?->toIso8601String(),
                'blocking_conversation_id' => $this->disclosedBlocker($block, $viewer, $canAccept),
                'overridable' => $canAccept && $viewer !== null && $viewer->holdsPermission(Permission::OverrideQueueRules),
            ];
        }

        [$code, $message] = match (true) {
            $viewer === null || ! $canAccept => [self::NOT_PERMITTED, null],
            default => $this->agentBlock($conversation, $viewer),
        };

        return [
            'allowed' => $code === null,
            'available' => true,
            'code' => $code,
            'message' => $message,
            'available_at' => null,
            'blocking_conversation_id' => null,
            'overridable' => false,
        ];
    }

    /**
     * The first of rules 1 to 3 that blocks the request, for display (no locks).
     */
    private function describedBlock(Conversation $conversation, CarbonImmutable $now): ?QueueBlock
    {
        if ($this->needsInformation($conversation)) {
            return new QueueBlock(self::NEEDS_INFO, self::NEEDS_INFO_MESSAGE);
        }

        $availableAt = $this->availableAt($conversation);

        if ($availableAt !== null && $availableAt->greaterThan($now)) {
            return new QueueBlock(self::MIN_WAIT, self::minWaitMessage($availableAt, $now), availableAt: $availableAt);
        }

        if (! $this->rulesFor($conversation)->chronologicalOrder()) {
            return null;
        }

        $key = (int) ($conversation->department_id ?? 0);

        if (self::$page === null) {
            $head = $this->head($conversation->department_id, $now);
        } else {
            if (! array_key_exists($key, self::$page['heads'])) {
                self::$page['heads'][$key] = $this->head($conversation->department_id, $now);
            }

            $head = self::$page['heads'][$key];
        }

        return $head !== null && $head->id !== $conversation->id && $this->precedes($head, $conversation)
            ? new QueueBlock(self::QUEUE_ORDER, self::QUEUE_ORDER_MESSAGE, blockingConversationId: $head->id)
            : null;
    }

    /**
     * The earlier request named by an order refusal, only for a viewer who
     * may know it exists: whoever can take requests from that queue, or can
     * see that conversation. A viewer who reaches the blocked row another
     * way (e.g. a supervisor, because it passed through their departments)
     * learns that an earlier request exists, never which one.
     */
    private function disclosedBlocker(QueueBlock $block, ?User $viewer, bool $canAccept): ?int
    {
        $id = $block->blockingConversationId;

        if ($id === null || $canAccept) {
            return $id;
        }

        if ($viewer === null) {
            return null;
        }

        $key = $viewer->id.':'.$id;
        $visible = self::$page['visible'][$key] ?? null;

        if ($visible === null) {
            $visible = Conversation::query()
                ->whereKey($id)
                ->tap(fn (Builder $query) => $this->access->restrictToVisible($query, $viewer))
                ->exists();

            if (self::$page !== null) {
                self::$page['visible'][$key] = $visible;
            }
        }

        return $visible ? $id : null;
    }

    /**
     * The viewer's own reason not to take a new conversation of that
     * department: their capacity limit, then the control of pending
     * conversations (the order the engine checks them in).
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function agentBlock(Conversation $conversation, User $viewer): array
    {
        $state = self::$page['agents'][$viewer->id] ?? null;

        if ($state === null) {
            $state = [
                'load' => $this->access->attentionLoad($viewer),
                'pending' => $this->pendingAttention->blocks($viewer),
            ];

            if (self::$page !== null) {
                self::$page['agents'][$viewer->id] = $state;
            }
        }

        $limit = $this->access->attentionLimit($viewer, $this->departmentOf($conversation));

        return match (true) {
            $limit !== null && $state['load'] >= $limit => [self::CAPACITY, self::capacityMessage($limit)],
            $state['pending'] => [self::PENDING_ATTENTION, PendingAttention::BLOCKED_MESSAGE],
            default => [null, null],
        };
    }

    public static function capacityMessage(int $limit): string
    {
        return "Ya atiendes el máximo de {$limit} conversaciones permitido. Cierra o libera alguna antes de aceptar otra.";
    }

    private function departmentOf(Conversation $conversation): ?Department
    {
        if ($conversation->department_id === null) {
            return null;
        }

        if ($conversation->relationLoaded('department')) {
            return $conversation->department;
        }

        $id = $conversation->department_id;

        if (self::$page === null) {
            return Department::query()->find($id);
        }

        if (! array_key_exists($id, self::$page['departments'])) {
            self::$page['departments'][$id] = Department::query()->find($id);
        }

        return self::$page['departments'][$id];
    }

    /**
     * Position of a request in its queue: its clock, then its id.
     *
     * @return array{0: int, 1: int}
     */
    private function position(Conversation $conversation): array
    {
        return [$this->clock($conversation)?->getTimestamp() ?? 0, (int) $conversation->id];
    }

    private function precedes(Conversation $earlier, Conversation $later): bool
    {
        return $this->position($earlier) < $this->position($later);
    }
}
