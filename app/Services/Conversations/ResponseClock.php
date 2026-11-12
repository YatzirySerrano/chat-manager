<?php

namespace App\Services\Conversations;

use App\Enums\AuditAction;
use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Enums\ResponseCycleKind;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\ResponseCycle;
use App\Models\User;
use App\Services\Analytics\BusinessHoursCalendar;
use App\Services\AuditLogger;
use App\Services\ServiceRules;
use App\Support\Features;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The response clock of a conversation: one response cycle
 * (`response_cycles`) per period in which a customer waits for a HUMAN
 * answer.
 *
 * - customerWrote() opens a cycle when a customer message is recorded on a
 *   live, unclosed conversation without an open one. The objective in force
 *   at that moment (ServiceRules: category > department > platform) is
 *   frozen in the row with its warning and deadline instants; a cycle is
 *   recorded even without objective, so response times exist independently
 *   of the SLA configuration.
 * - answered() ends it when a human reply is queued (same moment and rule
 *   as `conversations.first_response_at`).
 * - closed() ends it when the conversation is closed without an answer.
 * - sync() copies the responsible agent, department and category of the
 *   conversation to the open cycle. A reassignment never restarts the clock.
 *
 * Automatic messages, integration replies, notes and "message read" never
 * touch a cycle. Later rule changes, transfers or a new category never move
 * the deadline of a cycle that already started.
 *
 * Every method must run inside the transaction that holds the conversation
 * row lock (inbound receipt, reply or template queueing, lifecycle events),
 * so the cycle never diverges from its conversation; the unique
 * (conversation_id, active) index is the backstop.
 *
 * The open cycle is also pointed at by `conversations.open_response_cycle_id`,
 * written here when a cycle opens and ends. It is what lets sync(), closed()
 * and answered() find a cycle committed after the snapshot of a caller's
 * transaction without any ranged locking read (see openCycleId()).
 *
 * A deadline found exceeded when the cycle ends is recorded as a breach
 * right here, so the fact never depends on when the evaluator
 * (SlaEvaluator, `sla:evaluate`) last ran. One breach per cycle, ever.
 */
class ResponseClock
{
    /** Who found the deadline exceeded (audit property `detected_on`). */
    public const DETECTED_BY_EVALUATOR = 'evaluator';

    public const DETECTED_ON_ANSWER = 'answer';

    public const DETECTED_ON_CLOSE = 'close';

    public function __construct(
        private ServiceRules $rules,
        private BusinessHoursCalendar $calendar,
        private AuditLogger $audit,
    ) {}

    /**
     * A customer message sent at `$at` was recorded on the (locked)
     * conversation. Opens a cycle unless one is already open, the
     * conversation is closed or imported, or the message predates the
     * team's latest answer (a late delivery is not a new wait).
     */
    public function customerWrote(Conversation $conversation, CarbonInterface $at): ?ResponseCycle
    {
        if ($conversation->isImported() || $conversation->status === ConversationStatus::Closed) {
            return null;
        }

        $latest = ResponseCycle::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->first(['id', 'active', 'responded_at']);

        $now = CarbonImmutable::now()->startOfSecond();
        $startedAt = CarbonImmutable::instance($at)->startOfSecond();
        $startedAt = $startedAt->greaterThan($now) ? $now : $startedAt;

        if ($latest !== null && ($latest->isActive() || ($latest->responded_at !== null && $latest->responded_at->greaterThanOrEqualTo($startedAt)))) {
            return null;
        }

        $kind = $conversation->first_response_at === null ? ResponseCycleKind::FirstResponse : ResponseCycleKind::Response;
        $rules = $this->rules->forConversation($conversation);
        $target = $kind === ResponseCycleKind::FirstResponse ? $rules->firstResponseSeconds() : $rules->responseSeconds();
        $businessOnly = $rules->businessHoursOnly();

        $cycle = new ResponseCycle;
        $cycle->forceFill([
            'conversation_id' => $conversation->id,
            'kind' => $kind,
            'connection_id' => $conversation->connection_id,
            'department_id' => $conversation->department_id,
            'category_id' => $conversation->category_id,
            'assigned_user_id' => $conversation->assigned_user_id,
            'started_at' => $startedAt,
            'target_seconds' => $target,
            'business_hours_only' => $businessOnly,
            'active' => 1,
        ]);

        if ($target !== null) {
            $warnSeconds = intdiv($target * $rules->warningPercent(), 100);
            $department = $businessOnly ? $this->department($conversation) : null;

            $cycle->forceFill([
                'warn_at' => $businessOnly ? $this->calendar->addBusinessSeconds($startedAt, $warnSeconds, $department) : $startedAt->addSeconds($warnSeconds),
                'due_at' => $businessOnly ? $this->calendar->addBusinessSeconds($startedAt, $target, $department) : $startedAt->addSeconds($target),
            ]);
        }

        $cycle->save();
        $this->point($conversation->id, $cycle->id);

        return $cycle;
    }

    /**
     * A human reply was queued on the (locked) conversation: the customer
     * is no longer waiting.
     */
    public function answered(Conversation $conversation, User $responder): ?ResponseCycle
    {
        $cycle = $this->openCycle($conversation);

        if ($cycle === null) {
            return null;
        }

        $at = CarbonImmutable::now()->startOfSecond();
        $department = $this->department($conversation);

        $cycle->forceFill([
            'responded_at' => $at,
            'responder_user_id' => $responder->id,
            'response_seconds' => max(0, $at->getTimestamp() - $cycle->started_at->getTimestamp()),
            'business_response_seconds' => $this->calendar->businessSecondsBetween($cycle->started_at, $at, $department),
        ]);

        return $this->end($cycle, $conversation, ResponseCycle::OUTCOME_ANSWERED, $at, $department, self::DETECTED_ON_ANSWER);
    }

    /**
     * The (locked) conversation was closed while the customer was still
     * waiting: the cycle ends without an answer.
     */
    public function closed(Conversation $conversation, ?CarbonInterface $at = null): ?ResponseCycle
    {
        $cycle = $this->openCycle($conversation);

        if ($cycle === null) {
            return null;
        }

        $at = CarbonImmutable::instance($at ?? CarbonImmutable::now())->startOfSecond();

        return $this->end($cycle, $conversation, ResponseCycle::OUTCOME_CLOSED, $at->lessThan($cycle->started_at) ? CarbonImmutable::instance($cycle->started_at) : $at, null, self::DETECTED_ON_CLOSE);
    }

    /**
     * Copy who is responsible today (agent, department, category) to the
     * open cycle of the conversation. Lookups by primary or unique key, and
     * an update by primary key only when a cycle is open (see
     * openCycleIds()). Deadlines are never touched.
     */
    public function sync(Conversation $conversation): void
    {
        $ids = $this->openCycleIds($conversation);

        if ($ids === []) {
            return;
        }

        ResponseCycle::query()
            ->whereKey($ids)
            ->where('active', 1)
            ->update([
                'assigned_user_id' => $conversation->assigned_user_id,
                'department_id' => $conversation->department_id,
                'category_id' => $conversation->category_id,
            ]);
    }

    /**
     * Single hook of the engine (ConversationLifecycle::record()): a close
     * ends the open cycle; any other event keeps its responsibility in sync.
     */
    public function afterEvent(Conversation $conversation, ConversationEventType $type): void
    {
        if (in_array($type, [ConversationEventType::Created, ConversationEventType::SlaBreached, ConversationEventType::NoteAdded, ConversationEventType::NoteEdited], true)) {
            return;
        }

        if ($type === ConversationEventType::Closed || $conversation->status === ConversationStatus::Closed) {
            $this->closed($conversation);

            return;
        }

        $this->sync($conversation);
    }

    /**
     * Leave the trace of a breach that was just claimed for the cycle: one
     * timeline event and one audit entry. Called once per cycle, by whoever
     * set `breached_at` (the evaluator, or end() for a late answer or
     * close), inside the transaction that locks the conversation.
     */
    public function recordBreach(ResponseCycle $cycle, Conversation $conversation, string $detectedOn): void
    {
        app(ConversationLifecycle::class)->record($conversation, ConversationEventType::SlaBreached, null, [
            'from_user_id' => $cycle->breach_user_id,
            'from_department_id' => $cycle->department_id,
            'metadata' => [
                'cycle_id' => $cycle->id,
                'reason' => $cycle->kind->value,
                'target_seconds' => $cycle->target_seconds,
                'due_at' => $cycle->due_at?->toIso8601String(),
            ],
        ]);

        $this->audit->record(AuditAction::ConversationSlaBreached, $conversation, [
            'cycle_id' => $cycle->id,
            'objective' => $cycle->kind->label(),
            'target_minutes' => $cycle->target_seconds === null ? null : intdiv($cycle->target_seconds, 60),
            'business_hours_only' => $cycle->business_hours_only,
            'started_at' => $cycle->started_at->toIso8601String(),
            'due_at' => $cycle->due_at?->toIso8601String(),
            'responsible_user_id' => $cycle->breach_user_id,
            'department_id' => $cycle->department_id,
            'category_id' => $cycle->category_id,
            'detected_on' => $detectedOn,
        ]);
    }

    /**
     * Seconds over the deadline at `$until`, in the unit of the objective
     * (business time when the objective counts only business hours). Null
     * when the deadline has not passed, and also when the business time
     * cannot be computed (a span too long for the calendar): unknown is
     * stored as unknown, never as a figure in another unit.
     */
    public function exceededSeconds(ResponseCycle $cycle, CarbonInterface $until, ?Department $department): ?int
    {
        if ($cycle->due_at === null || $cycle->due_at->greaterThanOrEqualTo($until)) {
            return null;
        }

        return $cycle->business_hours_only
            ? $this->calendar->businessSecondsBetween($cycle->due_at, $until, $department)
            : $until->getTimestamp() - $cycle->due_at->getTimestamp();
    }

    private function end(ResponseCycle $cycle, Conversation $conversation, string $outcome, CarbonImmutable $at, ?Department $department, string $detectedOn): ResponseCycle
    {
        // While phase two is off (FEATURE_PHASE_TWO) a deadline frozen
        // before is not judged: the cycle ends without a breach, timeline
        // event or audit entry (nor any alert).
        $late = Features::phaseTwo() && $cycle->due_at !== null && $at->greaterThan($cycle->due_at);
        $newBreach = $late && $cycle->breached_at === null;

        if ($newBreach) {
            $cycle->forceFill([
                'breached_at' => $cycle->due_at,
                'breach_user_id' => $cycle->assigned_user_id,
            ]);
        }

        $cycle->forceFill([
            'ended_at' => $at,
            'outcome' => $outcome,
            'exceeded_seconds' => $cycle->breached_at === null
                ? null
                : $this->exceededSeconds($cycle, $at, $cycle->business_hours_only ? ($department ?? $this->department($conversation)) : null),
            'active' => null,
        ])->save();
        $this->point($cycle->conversation_id, null, $cycle->id);

        if ($newBreach) {
            $this->recordBreach($cycle, $conversation, $detectedOn);
        }

        return $cycle;
    }

    /**
     * The open cycle, locked by primary key (a current read) and re-checked,
     * so a breach claimed by the evaluator a moment ago is seen and a cycle
     * that already ended is never taken as open.
     */
    private function openCycle(Conversation $conversation): ?ResponseCycle
    {
        foreach ($this->openCycleIds($conversation) as $id) {
            $cycle = ResponseCycle::query()->lockForUpdate()->find($id);

            if ($cycle !== null && $cycle->isActive()) {
                return $cycle;
            }
        }

        return null;
    }

    /**
     * Ids that may be the open cycle of the conversation (the caller
     * re-checks `active` by primary key), found without any ranged locking
     * read:
     *
     * 1. `conversations.open_response_cycle_id`, with a locking read of the
     *    conversation row by primary key. The caller already holds that row
     *    lock, so it costs nothing, and being a current read it returns the
     *    latest committed pointer even when the caller's transaction took
     *    its REPEATABLE READ snapshot before the customer wrote (a release
     *    of conversations inside a user or role update, a close decided on
     *    an older snapshot). A snapshot read alone missed that cycle: its
     *    responsible agent was never updated and a close left it open.
     * 2. Only when the pointer is empty, the plain (non-locking) read by
     *    (conversation_id, active) kept from before, for a cycle that did
     *    not get its pointer (rows written outside this class).
     *
     * A locking read or an UPDATE by (conversation_id, active) is avoided on
     * purpose: when it matches no row it leaves a gap lock on that unique
     * index for the rest of the transaction and makes the cycles of other
     * conversations being received wait for it. Both lookups here touch
     * existing rows by key only, under either isolation level. The
     * conversation row lock serializes everything that opens or ends this
     * conversation's cycle, so the pointer cannot move while it is held.
     *
     * The locking read is SHARED, never exclusive. Not every caller holds
     * the exclusive conversation lock: an event recorded by a delivery
     * worker (a reply blocked because its connection was removed) only
     * holds the shared lock its event row took on the conversation through
     * the foreign key. Two such workers asking for the exclusive lock at
     * once deadlocked on the upgrade. A shared lock is a current read too,
     * is granted next to those foreign key locks and next to the caller's
     * own exclusive lock, and still keeps the pointer from moving, because
     * moving it needs the exclusive lock.
     *
     * @return list<int>
     */
    private function openCycleIds(Conversation $conversation): array
    {
        $pointer = DB::table('conversations')
            ->where('id', $conversation->id)
            ->sharedLock()
            ->value('open_response_cycle_id');

        if (is_int($pointer)) {
            return [$pointer];
        }

        $id = ResponseCycle::query()
            ->where('conversation_id', $conversation->id)
            ->where('active', 1)
            ->value('id');

        return is_int($id) ? [$id] : [];
    }

    /**
     * Keep `conversations.open_response_cycle_id` (by primary key, without
     * touching `updated_at`): set when a cycle opens, cleared when that
     * same cycle ends.
     */
    private function point(int $conversationId, ?int $cycleId, ?int $onlyIfPointingAt = null): void
    {
        DB::table('conversations')
            ->where('id', $conversationId)
            ->when($onlyIfPointingAt !== null, fn ($query) => $query->where('open_response_cycle_id', $onlyIfPointingAt))
            ->update(['open_response_cycle_id' => $cycleId]);
    }

    private function department(Conversation $conversation): ?Department
    {
        return $conversation->department_id === null ? null : Department::query()->find($conversation->department_id);
    }
}
