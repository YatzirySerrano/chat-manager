<?php

namespace App\Services\Conversations;

use App\Actions\Conversations\AssignAutomatically;
use App\Enums\ConversationStatus;
use App\Enums\Permission;
use App\Models\Conversation;
use App\Models\User;
use App\Services\PlatformSettings;
use App\Support\Personalization\ViewerAlias;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * What "pendiente de atención" means, in one place, for the control of new
 * conversations, the automatic distribution, the inbox and «Seguimiento de
 * atención».
 *
 * A conversation is waiting for its agent's answer when it is in attention
 * (open, or a legacy on_hold row) with a responsible agent and the customer
 * wrote after the last reply of the team (`last_inbound_at` later than
 * `last_outbound_at`). Automatic messages never set `last_outbound_at`, so a
 * welcome or away message does not count as an answer; internal notes are
 * not answers either. Conversations waiting in a queue (no agent), waiting
 * for the customer (the team answered last) and closed ones never count, and
 * a conversation handed to someone else stops counting for the previous
 * agent at once (it follows `assigned_user_id`).
 *
 * It is overdue once it has waited for this agent longer than the tolerance
 * configured in operations.pending_block_after_minutes. The wait for the
 * agent starts at the later of the customer's message and the assignment
 * (CLOCK_SQL): a conversation that waited in a queue gives the agent who
 * takes it the whole tolerance, instead of blocking them at once. While the control is on
 * (operations.pending_block_enabled), an agent with at least one overdue
 * conversation cannot accept new ones nor receive them automatically,
 * unless their role holds conversations.accept-with-pending. It never
 * limits the internal chat, replies, transfers or the assignments made by
 * people who distribute work.
 *
 * @phpstan-type BlockState array{
 *     applies: bool,
 *     blocked: bool,
 *     overdue: int,
 *     awaiting: int,
 *     oldest_waiting_since: string|null,
 *     next_change_at: string|null,
 *     tolerance_minutes: int,
 *     message: string|null,
 *     conversations: list<array{id: int, contact: string, waiting_since: string|null, overdue: bool}>,
 * }
 */
class PendingAttention
{
    public const BLOCKED_MESSAGE = 'Tienes conversaciones pendientes de atención. Revísalas antes de aceptar una nueva.';

    /** Waiting conversations listed to the agent, oldest first. */
    public const LISTED = 5;

    /** Since when a waiting conversation waits for its current agent. */
    public const CLOCK_SQL = 'GREATEST(conversations.last_inbound_at, COALESCE(conversations.assigned_at, conversations.last_inbound_at))';

    public function __construct(private PlatformSettings $settings) {}

    public function isEnabled(): bool
    {
        return $this->settings->get('operations.pending_block_enabled') === true;
    }

    public function toleranceMinutes(): int
    {
        $minutes = $this->settings->get('operations.pending_block_after_minutes');

        return is_numeric($minutes) ? max(0, (int) $minutes) : 10;
    }

    /**
     * Whether the control applies to the agent (it is on and their role is
     * not exempt).
     */
    public function appliesTo(User $agent): bool
    {
        return $this->isEnabled() && ! $agent->holdsPermission(Permission::AcceptWithPendingConversations);
    }

    /**
     * Conversations in attention whose customer is waiting for the team's
     * answer (any agent).
     *
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    public function awaiting(Builder $query): Builder
    {
        return $query
            ->live()
            ->notHistoryOnly()
            ->whereIn($query->qualifyColumn('status'), ConversationStatus::attending())
            ->whereNotNull($query->qualifyColumn('assigned_user_id'))
            ->whereNotNull($query->qualifyColumn('last_inbound_at'))
            ->where(fn (Builder $answered) => $answered
                ->whereNull($answered->qualifyColumn('last_outbound_at'))
                ->orWhereColumn($answered->qualifyColumn('last_inbound_at'), '>', $answered->qualifyColumn('last_outbound_at')));
    }

    /**
     * Of those, the ones waiting longer than the tolerance.
     *
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    public function overdue(Builder $query, ?CarbonInterface $now = null): Builder
    {
        return $this->awaiting($query)->whereRaw(self::CLOCK_SQL.' <= ?', [$this->threshold($now)]);
    }

    /**
     * Whether this conversation, as loaded, waits for its agent's answer
     * longer than the tolerance (before an answer is recorded on it).
     */
    public function isOverdue(Conversation $conversation, ?CarbonInterface $now = null): bool
    {
        $inbound = $conversation->last_inbound_at;

        if ($inbound === null
            || $conversation->assigned_user_id === null
            || ! in_array($conversation->status, ConversationStatus::attending(), true)
            || ($conversation->last_outbound_at !== null && ! $inbound->greaterThan($conversation->last_outbound_at))) {
            return false;
        }

        $clock = $conversation->assigned_at !== null && $conversation->assigned_at->greaterThan($inbound) ? $conversation->assigned_at : $inbound;

        return $clock->lessThanOrEqualTo($this->threshold($now));
    }

    /**
     * Answering an overdue conversation may lift the agent's block: the
     * automatic queues are offered to them again once the answer is
     * committed. Call it with isOverdue() read before the answer was saved.
     */
    public function answered(Conversation $conversation, bool $wasOverdue): void
    {
        if ($wasOverdue && $this->isEnabled()) {
            app(AssignAutomatically::class)->distributeForAgentAfterCommit($conversation->assigned_user_id);
        }
    }

    /**
     * Moment before which a customer message is overdue.
     */
    public function threshold(?CarbonInterface $now = null): CarbonImmutable
    {
        return CarbonImmutable::instance($now ?? now())->subMinutes($this->toleranceMinutes());
    }

    /**
     * Whether the agent may not take a new conversation right now. With
     * `lockingRead`, the conversations are read past the snapshot (inside a
     * transaction that holds the agent row locked), so two requests of the
     * same agent never both slip through.
     */
    public function blocks(User $agent, bool $lockingRead = false): bool
    {
        if (! $this->appliesTo($agent)) {
            return false;
        }

        return $this->overdue(Conversation::query())
            ->where('assigned_user_id', $agent->id)
            ->when($lockingRead, fn (Builder $query) => $query->sharedLock())
            ->exists();
    }

    /**
     * The control as the agent's screens show it: whether they are blocked,
     * how many customers are waiting for them, since when, and when the
     * state will change by itself (a waiting customer crossing the
     * tolerance), so the screen refreshes then without polling.
     *
     * @return BlockState
     */
    public function stateFor(User $agent, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now());
        $applies = $this->appliesTo($agent);

        $summary = $this->awaiting(Conversation::query())
            ->where('assigned_user_id', $agent->id)
            ->toBase()
            ->selectRaw('COUNT(*) as awaiting, MIN(last_inbound_at) as oldest')
            ->selectRaw('SUM(CASE WHEN '.self::CLOCK_SQL.' <= ? THEN 1 ELSE 0 END) as overdue', [$this->threshold($now)])
            ->selectRaw('MIN(CASE WHEN '.self::CLOCK_SQL.' > ? THEN '.self::CLOCK_SQL.' END) as next_overdue', [$this->threshold($now)])
            ->first();

        $awaiting = (int) ($summary->awaiting ?? 0);
        $overdue = (int) ($summary->overdue ?? 0);
        $oldest = is_string($summary->oldest ?? null) ? CarbonImmutable::parse($summary->oldest) : null;
        $nextOverdue = is_string($summary->next_overdue ?? null) ? CarbonImmutable::parse($summary->next_overdue) : null;
        $blocked = $applies && $overdue > 0;

        return [
            'applies' => $applies,
            'blocked' => $blocked,
            'overdue' => $overdue,
            'awaiting' => $awaiting,
            'oldest_waiting_since' => $oldest?->toIso8601String(),
            // Only a not yet overdue customer can change the state by itself.
            'next_change_at' => $applies && ! $blocked && $nextOverdue !== null
                ? $nextOverdue->addMinutes($this->toleranceMinutes())->toIso8601String()
                : null,
            'tolerance_minutes' => $this->toleranceMinutes(),
            'message' => $blocked ? self::BLOCKED_MESSAGE : null,
            // The way straight to them from the notice (only shown when blocked).
            'conversations' => $blocked ? $this->oldestWaiting($agent, $now) : [],
        ];
    }

    /**
     * @return list<array{id: int, contact: string, waiting_since: string|null, overdue: bool}>
     */
    private function oldestWaiting(User $agent, CarbonImmutable $now): array
    {
        $rows = $this->awaiting(Conversation::query())
            ->where('assigned_user_id', $agent->id)
            ->with('contact:id,name,profile_name,phone_e164')
            ->orderBy('last_inbound_at')
            ->orderBy('id')
            ->limit(self::LISTED)
            ->get(['id', 'contact_id', 'assigned_user_id', 'status', 'last_inbound_at', 'last_outbound_at', 'assigned_at']);

        // The agent's own private aliases (PERSONALIZATION).
        ViewerAlias::load($rows, $agent);

        return array_values($rows->map(fn (Conversation $conversation): array => [
            'id' => $conversation->id,
            'contact' => ViewerAlias::displayName($conversation->contact, ViewerAlias::of($conversation->contact, $agent)),
            'waiting_since' => $conversation->last_inbound_at?->toIso8601String(),
            'overdue' => $this->isOverdue($conversation, $now),
        ])->all());
    }
}
