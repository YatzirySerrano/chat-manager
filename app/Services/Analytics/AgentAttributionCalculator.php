<?php

namespace App\Services\Analytics;

use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Models\Conversation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the per-person rows of a conversation (conversation_agent_metrics)
 * from its append-only events and its messages: who was responsible and for
 * how long, who replied, who wrote the first human response and who was
 * responsible at each closing. The rows are replaced as a whole, so running
 * it twice, late or out of order gives the same result.
 *
 * Responsibility timeline (events in order of creation):
 * - accepted, assigned, auto_assigned, reopened with `to_user_id`: that
 *   person becomes responsible;
 * - transferred: the destination agent becomes responsible (nobody when the
 *   transfer targets a department); it counts as a transfer out for the
 *   origin agent and in for the destination agent, and for neither when
 *   both are the same person (the agent moved their own conversation to
 *   another department and stayed responsible);
 * - unassigned: nobody is responsible;
 * - closed: the interval ends and the closing is credited to the person
 *   responsible at that instant (`from_user_id` of the event).
 *
 * `handling_seconds` sums the finished intervals only. A running interval is
 * stored as `open_since` when the conversation itself confirms it (it is
 * not closed and its assignee is that person); it is never frozen as a
 * duration.
 *
 * Attention by a person: accepting, receiving it by a manual assignment or
 * a transfer, or replying. Being chosen by the automatic distribution makes
 * the person responsible but is not attention (ADR-REP-4).
 *
 * When the events do not explain the current assignee (data created before
 * the event log, or outside the normal actions), the assignee still gets a
 * row (`was_responsible`, `responsible_now`) without intervals: the time is
 * unknown and stays out of every average instead of being guessed.
 *
 * @phpstan-import-type AgentRow from AgentTimeline
 */
class AgentAttributionCalculator
{
    /**
     * Events that move the responsibility of a conversation.
     *
     * @var list<ConversationEventType>
     */
    private const TIMELINE = [
        ConversationEventType::Accepted,
        ConversationEventType::Assigned,
        ConversationEventType::AutoAssigned,
        ConversationEventType::Transferred,
        ConversationEventType::Unassigned,
        ConversationEventType::Closed,
        ConversationEventType::Reopened,
    ];

    /**
     * Replace the rows of the conversation (removes them when it has none).
     */
    public function recompute(Conversation $conversation): void
    {
        $rows = $this->rowsFor($conversation);
        $now = now();
        $dimensions = [
            'conversation_id' => $conversation->id,
            'connection_id' => $conversation->connection_id,
            'department_id' => $conversation->department_id,
            'category_id' => $conversation->category_id,
            'status' => $conversation->status->value,
            'received_at' => CarbonImmutable::parse($conversation->created_at),
            'computed_at' => $now,
        ];

        // Rows of people who no longer take part are removed by primary key,
        // found with a plain read: a ranged DELETE that matches nothing takes
        // a gap lock on (conversation_id, user_id), and two conversations
        // recomputed at once then deadlocked on each other's insert. No
        // transaction is needed either: the rows are derived, the per
        // conversation lock of RefreshConversationMetrics serializes the
        // recomputations and the next one repairs an interrupted pass.
        $this->deleteRows($conversation->id, keepUserIds: array_keys($rows));

        if ($rows === []) {
            return;
        }

        $records = [];

        foreach ($rows as $userId => $row) {
            $records[] = ['user_id' => $userId, ...$dimensions, ...$row];
        }

        DB::table('conversation_agent_metrics')->upsert(
            $records,
            ['conversation_id', 'user_id'],
            array_values(array_diff(array_keys($records[0]), ['conversation_id', 'user_id'])),
        );
    }

    /**
     * Remove every row of a conversation that must not be measured.
     */
    public function forget(int $conversationId): void
    {
        $this->deleteRows($conversationId);
    }

    /**
     * Delete the conversation's rows by primary key (record locks only),
     * except those of the given people.
     *
     * @param  list<int>  $keepUserIds
     */
    private function deleteRows(int $conversationId, array $keepUserIds = []): void
    {
        $staleIds = DB::table('conversation_agent_metrics')
            ->where('conversation_id', $conversationId)
            ->when($keepUserIds !== [], fn ($query) => $query->whereNotIn('user_id', $keepUserIds))
            ->pluck('id')
            ->all();

        if ($staleIds !== []) {
            DB::table('conversation_agent_metrics')->whereIn('id', $staleIds)->delete();
        }
    }

    /**
     * @return array<int, AgentRow> Rows keyed by user id.
     */
    public function rowsFor(Conversation $conversation): array
    {
        $timeline = new AgentTimeline;

        foreach ($this->events($conversation->id) as $event) {
            $at = CarbonImmutable::parse($event->created_at);
            $to = $event->to_user_id === null ? null : (int) $event->to_user_id;
            $from = $event->from_user_id === null ? null : (int) $event->from_user_id;

            switch (ConversationEventType::from($event->type)) {
                case ConversationEventType::Accepted:
                case ConversationEventType::Assigned:
                    if ($to !== null) {
                        $timeline->start($to, $at);
                        $timeline->attend($to, $at);
                    }

                    break;
                case ConversationEventType::AutoAssigned:
                case ConversationEventType::Reopened:
                    if ($to !== null) {
                        $timeline->start($to, $at);
                    }

                    break;
                case ConversationEventType::Transferred:
                    // Moved to another department by its own agent, who stays
                    // responsible: nobody handed it over or received it.
                    $keepsAgent = $from !== null && $from === $to;

                    if ($from !== null && ! $keepsAgent) {
                        $timeline->transferOut($from);
                    }

                    if ($to !== null) {
                        $timeline->start($to, $at);
                        $timeline->attend($to, $at);

                        if (! $keepsAgent) {
                            $timeline->transferIn($to);
                        }
                    } else {
                        $timeline->stop($at);
                    }

                    break;
                case ConversationEventType::Unassigned:
                    $timeline->stop($at);

                    break;
                case ConversationEventType::Closed:
                    $owner = $from ?? $timeline->current();

                    if ($owner !== null) {
                        $timeline->close($owner, $at);
                    }

                    $timeline->stop($at);

                    break;
                default:
                    break;
            }
        }

        $timeline->settle(
            $conversation->assigned_user_id === null ? null : (int) $conversation->assigned_user_id,
            $conversation->status === ConversationStatus::Closed,
        );

        $replies = $this->humanReplies($conversation->id)
            ->selectRaw('sender_user_id, COUNT(*) AS replies, MIN(created_at) AS first_reply_at')
            ->groupBy('sender_user_id')
            ->get();

        foreach ($replies as $reply) {
            $timeline->replied((int) $reply->sender_user_id, (int) $reply->replies, CarbonImmutable::parse((string) $reply->first_reply_at));
        }

        $first = $this->humanReplies($conversation->id)->orderBy('created_at')->orderBy('id')->first(['sender_user_id', 'created_at']);

        if ($first !== null) {
            $timeline->authoredFirstResponse(
                (int) $first->sender_user_id,
                max(0, (int) CarbonImmutable::parse($conversation->created_at)->diffInSeconds(CarbonImmutable::parse((string) $first->created_at), false)),
            );
        }

        return $timeline->rows();
    }

    /**
     * Outbound messages written by a person, not automatic, that the
     * provider accepted (same definition as the first human response).
     */
    private function humanReplies(int $conversationId): Builder
    {
        return DB::table('messages')
            ->where('conversation_id', $conversationId)
            ->where('direction', MessageDirection::Outbound->value)
            ->whereNotNull('sender_user_id')
            ->whereIn('status', ConversationMetricsCalculator::ACCEPTED_STATUSES)
            ->whereRaw("NOT (COALESCE(JSON_VALUE(metadata, '$.automatic'), 'false') IN ('true', '1'))");
    }

    /**
     * @return Collection<int, object{type: string, from_user_id: int|string|null, to_user_id: int|string|null, created_at: string}>
     */
    private function events(int $conversationId): Collection
    {
        /** @var Collection<int, object{type: string, from_user_id: int|string|null, to_user_id: int|string|null, created_at: string}> $events */
        $events = DB::table('conversation_events')
            ->where('conversation_id', $conversationId)
            ->whereIn('type', array_map(fn (ConversationEventType $type): string => $type->value, self::TIMELINE))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['type', 'from_user_id', 'to_user_id', 'created_at']);

        return $events;
    }
}
