<?php

namespace App\Services\Sla;

use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\ResponseCycle;
use App\Notifications\NotificationRecipients;
use App\Notifications\SlaAlertNotification;
use App\Services\Conversations\ResponseClock;
use App\Services\ServiceRules;
use App\Support\Features;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Moves the open response cycles through their SLA stages as time passes
 * (`sla:evaluate`, every minute): «por vencer» at `warn_at`, «vencida» at
 * `due_at`, and bounded reminders while a breached cycle stays open. Nobody
 * has to send or open anything for a deadline to pass, and the recipient
 * does not need to be connected: the notification waits in their bell.
 *
 * Idempotent by construction, not by scheduling: every transition is
 * CLAIMED with one atomic conditional UPDATE on the cycle row
 * (`warned_at IS NULL`, `breached_at IS NULL`, `reminders_count = <seen>`)
 * and only the run whose UPDATE changed the row acts on it. Two overlapping
 * runs, two servers or a retry can therefore never raise the same warning,
 * breach or reminder twice; the notification receipts (one per recipient,
 * cycle and stage) are a second barrier.
 *
 * A breach is claimed inside a transaction that locks the conversation
 * first (the engine's lock order), together with its single timeline event
 * and audit entry (ResponseClock::recordBreach()); a reply being queued at
 * the same instant either ends the cycle first (and records the breach
 * itself when it was late) or sees the breach already claimed. Warnings and
 * reminders are single-statement claims that take no other lock.
 *
 * Bounded: each stage reads at most BATCH candidates per pass through the
 * (active, breached_at, due_at) / (active, warned_at, warn_at) indexes, at
 * most MAX_PASSES passes per run; what is left is handled a minute later.
 * The pending stage is an equality in the index (`breached_at IS NULL`,
 * `warned_at IS NULL`) followed by the range on the date, so the cycles a
 * previous run already processed and that are still open are never read
 * again: with nothing due, breaches and warnings are two index reads that
 * return no row however many breached requests keep waiting. Reminders
 * (only when a frequency is configured) range over the breached open
 * cycles, which is the set they are about.
 *
 * Notifications are sent after the claim committed. A crash in between
 * loses that notification, never the fact (the breach stays recorded and
 * shown everywhere); a failure claiming or notifying one cycle is reported
 * and does not stop the others.
 */
class SlaEvaluator
{
    /** Candidates read per stage and pass. */
    public const BATCH = 200;

    /** Passes per stage and run. */
    public const MAX_PASSES = 10;

    public function __construct(
        private ServiceRules $rules,
        private ResponseClock $clock,
        private NotificationRecipients $recipients,
    ) {}

    /**
     * @return array{breached: int, warned: int, reminded: int}
     */
    public function run(?CarbonImmutable $now = null): array
    {
        // Phase two only: while FEATURE_PHASE_TWO is off nothing is warned,
        // breached or reminded, even when the command is run by hand. Cycles
        // without objective (all of those opened while it was off) never
        // have a deadline, so turning it on raises no stale alerts for them.
        if (! Features::phaseTwo()) {
            return ['breached' => 0, 'warned' => 0, 'reminded' => 0];
        }

        $now = ($now ?? CarbonImmutable::now())->startOfSecond();

        // Breaches first: a cycle found already past its deadline (the
        // scheduler was down) is announced as breached, not as «por vencer».
        return [
            'breached' => $this->breaches($now),
            'warned' => $this->warnings($now),
            'reminded' => $this->reminders($now),
        ];
    }

    /**
     * Claim the breach of one cycle. True only for the caller that set
     * `breached_at`; false when it was already breached or already ended.
     */
    public function claimBreach(int $cycleId, CarbonImmutable $now): bool
    {
        $conversationId = ResponseCycle::query()->whereKey($cycleId)->value('conversation_id');

        if (! is_int($conversationId)) {
            return false;
        }

        return DB::transaction(function () use ($cycleId, $conversationId, $now): bool {
            $conversation = Conversation::query()->lockForUpdate()->find($conversationId);

            if ($conversation === null) {
                return false;
            }

            // Safety net: a cycle left open on a closed conversation ends
            // with the close (which records the breach itself if it was late).
            if ($conversation->status === ConversationStatus::Closed) {
                $this->clock->closed($conversation, $conversation->closed_at);

                return false;
            }

            $claimed = ResponseCycle::query()
                ->whereKey($cycleId)
                ->where('active', 1)
                ->whereNull('breached_at')
                ->whereNotNull('due_at')
                ->where('due_at', '<', $now)
                ->update([
                    'breached_at' => DB::raw('due_at'),
                    'breach_user_id' => DB::raw('assigned_user_id'),
                    'last_alert_at' => $now,
                ]);

            if ($claimed !== 1) {
                return false;
            }

            $this->clock->recordBreach(ResponseCycle::query()->findOrFail($cycleId), $conversation, ResponseClock::DETECTED_BY_EVALUATOR);

            return true;
        });
    }

    /**
     * Claim the «por vencer» warning of one cycle (single atomic statement).
     */
    public function claimWarning(int $cycleId, CarbonImmutable $now): bool
    {
        return ResponseCycle::query()
            ->whereKey($cycleId)
            ->where('active', 1)
            ->whereNull('warned_at')
            ->whereNotNull('warn_at')
            ->where('warn_at', '<=', $now)
            ->update(['warned_at' => $now]) === 1;
    }

    /**
     * Claim reminder number `$seen + 1` of a breached, still open cycle
     * (compare-and-swap on the counter).
     */
    public function claimReminder(int $cycleId, int $seen, CarbonImmutable $now): bool
    {
        return ResponseCycle::query()
            ->whereKey($cycleId)
            ->where('active', 1)
            ->whereNotNull('breached_at')
            ->where('reminders_count', $seen)
            ->update(['reminders_count' => $seen + 1, 'last_alert_at' => $now]) === 1;
    }

    /**
     * Open cycles past their deadline that nobody claimed yet, oldest
     * deadline first: equality on (active, breached_at) and range on
     * `due_at` in the same index, which also gives the order.
     *
     * @return Builder<ResponseCycle>
     */
    public function breachCandidates(CarbonImmutable $now): Builder
    {
        return ResponseCycle::query()
            ->where('active', 1)
            ->whereNull('breached_at')
            ->where('due_at', '<', $now)
            ->orderBy('due_at')
            ->limit(self::BATCH);
    }

    /**
     * Open cycles past their warning instant that were not warned yet:
     * equality on (active, warned_at) and range on `warn_at`.
     *
     * @return Builder<ResponseCycle>
     */
    public function warningCandidates(CarbonImmutable $now): Builder
    {
        return ResponseCycle::query()
            ->where('active', 1)
            ->whereNull('warned_at')
            ->where('warn_at', '<=', $now)
            ->orderBy('warn_at')
            ->limit(self::BATCH);
    }

    /**
     * Breached cycles still open that are due for another reminder. A
     * breach is always stamped with its deadline (`breached_at` = `due_at`),
     * so the range on `breached_at` reads only the breached open cycles
     * through (active, breached_at, due_at), oldest deadline first.
     *
     * @return Builder<ResponseCycle>
     */
    public function reminderCandidates(CarbonImmutable $now, int $minutes, int $limit): Builder
    {
        return ResponseCycle::query()
            ->where('active', 1)
            ->where('breached_at', '<', $now)
            ->where('reminders_count', '<', $limit)
            ->where('last_alert_at', '<=', $now->subMinutes($minutes))
            ->orderBy('breached_at')
            ->limit(self::BATCH);
    }

    private function breaches(CarbonImmutable $now): int
    {
        $alerts = $this->rules->alerts();
        $count = 0;

        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $ids = $this->breachCandidates($now)->pluck('id');

            foreach ($ids as $id) {
                // One cycle that cannot be claimed (a deadlock, bad data)
                // is reported and retried next run; it never stops the
                // other breaches, the warnings or the reminders.
                try {
                    if (! $this->claimBreach((int) $id, $now)) {
                        continue;
                    }
                } catch (Throwable $exception) {
                    report($exception);

                    continue;
                }

                $count++;
                $this->notify((int) $id, SlaAlertNotification::STAGE_BREACH, $alerts['agent_breach'], $alerts['supervisor_breach']);
            }

            if ($ids->count() < self::BATCH) {
                break;
            }
        }

        return $count;
    }

    private function warnings(CarbonImmutable $now): int
    {
        $alerts = $this->rules->alerts();
        $count = 0;

        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            /** @var Collection<int, ResponseCycle> $cycles */
            $cycles = $this->warningCandidates($now)->get(['id', 'due_at', 'breached_at']);

            foreach ($cycles as $cycle) {
                if (! $this->claimWarning($cycle->id, $now)) {
                    continue;
                }

                // Already breached (announced as such a moment ago): the
                // warning is only marked, nobody is told twice.
                if ($cycle->breached_at !== null || ($cycle->due_at !== null && $cycle->due_at->lessThan($now))) {
                    continue;
                }

                $count++;
                $this->notify($cycle->id, SlaAlertNotification::STAGE_WARNING, $alerts['agent_warning'], $alerts['supervisor_warning']);
            }

            if ($cycles->count() < self::BATCH) {
                break;
            }
        }

        return $count;
    }

    private function reminders(CarbonImmutable $now): int
    {
        $alerts = $this->rules->alerts();
        $minutes = $alerts['reminder_minutes'];

        if ($minutes === null || $alerts['reminder_limit'] < 1) {
            return 0;
        }

        $count = 0;

        /** @var Collection<int, ResponseCycle> $cycles */
        $cycles = $this->reminderCandidates($now, $minutes, $alerts['reminder_limit'])->get(['id', 'reminders_count']);

        foreach ($cycles as $cycle) {
            if (! $this->claimReminder($cycle->id, $cycle->reminders_count, $now)) {
                continue;
            }

            $count++;
            $this->notify($cycle->id, SlaAlertNotification::STAGE_BREACH, $alerts['agent_breach'], $alerts['supervisor_breach'], $cycle->reminders_count + 1);
        }

        return $count;
    }

    /**
     * Notify the responsible agent and the supervisors of the conversation,
     * each group only when its setting is on. The agent is never notified
     * twice (as agent and as supervisor), and nobody outside the
     * conversation's visibility is notified at all.
     */
    private function notify(int $cycleId, string $stage, bool $toAgent, bool $toSupervisors, int $reminder = 0): void
    {
        if (! $toAgent && ! $toSupervisors) {
            return;
        }

        try {
            $cycle = ResponseCycle::query()->find($cycleId);
            $conversation = $cycle === null ? null : Conversation::query()->find($cycle->conversation_id);

            if ($cycle === null || $conversation === null) {
                return;
            }

            $agent = $toAgent ? $this->recipients->ifCanView($cycle->assigned_user_id, $conversation) : null;

            $agent?->notify(new SlaAlertNotification($stage, $cycle->id, $conversation->id, $conversation->department_id, false, $reminder));

            if (! $toSupervisors) {
                return;
            }

            $supervisors = $this->recipients->slaSupervisors($conversation, $cycle->assigned_user_id);

            if ($supervisors->isNotEmpty()) {
                Notification::send($supervisors, new SlaAlertNotification($stage, $cycle->id, $conversation->id, $conversation->department_id, true, $reminder));
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
