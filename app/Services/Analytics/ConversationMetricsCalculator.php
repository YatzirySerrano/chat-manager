<?php

namespace App\Services\Analytics;

use App\Enums\ConversationEventType;
use App\Enums\MessageDirection;
use App\Models\Conversation;
use App\Models\ConversationMetric;
use App\Models\Department;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes the metrics row of a conversation from its source data (the
 * conversation, its append-only events and its messages). The row is always
 * rebuilt as a whole, so applying it twice, late or out of order gives the
 * same result: it is idempotent and coherent with the source by
 * construction.
 *
 * Definitions (calendar time, in seconds):
 * - first attention: creation → first acceptance, assignment, automatic
 *   assignment or transfer to a specific agent;
 * - first human response: creation → first outbound message written by a
 *   person (sender set, not flagged `metadata.automatic`) that the provider
 *   accepted (accepted, sent, delivered or read). Failed or still queued
 *   messages do not count;
 * - resolution: creation → first closing.
 */
class ConversationMetricsCalculator
{
    /**
     * Outbound statuses proving that the message reached the provider.
     *
     * @var list<string>
     */
    public const ACCEPTED_STATUSES = ['accepted', 'sent', 'delivered', 'read'];

    public function __construct(private BusinessHoursCalendar $calendar) {}

    /**
     * Recompute and store the row; returns null (and removes any stale row)
     * when the conversation does not exist.
     */
    public function recompute(int $conversationId): ?ConversationMetric
    {
        $conversation = Conversation::query()->find($conversationId);

        if ($conversation === null || $conversation->created_at === null) {
            ConversationMetric::query()->where('conversation_id', $conversationId)->delete();

            return null;
        }

        $attributes = $this->attributesFor($conversation);

        ConversationMetric::query()->upsert(
            [['conversation_id' => $conversation->id, ...$attributes]],
            ['conversation_id'],
            array_keys($attributes),
        );

        return ConversationMetric::query()->where('conversation_id', $conversation->id)->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesFor(Conversation $conversation): array
    {
        $receivedAt = CarbonImmutable::parse($conversation->created_at);
        $events = $this->eventFigures($conversation->id);
        $messages = $this->messageFigures($conversation->id);

        return [
            'connection_id' => $conversation->connection_id,
            'department_id' => $conversation->department_id,
            'agent_id' => $conversation->assigned_user_id,
            'status' => $conversation->status->value,
            'received_at' => $receivedAt,
            'first_assigned_at' => $events['first_assigned_at'],
            'first_human_response_at' => $messages['first_human_response_at'],
            'resolved_at' => $events['resolved_at'],
            'last_closed_at' => $events['last_closed_at'],
            'seconds_to_first_attention' => $this->secondsBetween($receivedAt, $events['first_assigned_at']),
            'seconds_to_first_human_response' => $this->secondsBetween($receivedAt, $messages['first_human_response_at']),
            'seconds_to_resolution' => $this->secondsBetween($receivedAt, $events['resolved_at']),
            'closed_count' => $events['closed_count'],
            'reopened_count' => $events['reopened_count'],
            'transfers_count' => $events['transfers_count'],
            'messages_in' => $messages['messages_in'],
            'messages_out' => $messages['messages_out'],
            'failed_out' => $messages['failed_out'],
            'out_of_hours' => $this->calendar->isOutsideHours($receivedAt, $this->receivingDepartment($conversation)),
            'computed_at' => now(),
        ];
    }

    /**
     * @return array{first_assigned_at: CarbonImmutable|null, resolved_at: CarbonImmutable|null, last_closed_at: CarbonImmutable|null, closed_count: int, reopened_count: int, transfers_count: int}
     */
    private function eventFigures(int $conversationId): array
    {
        $attention = [ConversationEventType::Accepted->value, ConversationEventType::Assigned->value, ConversationEventType::AutoAssigned->value];
        $closed = ConversationEventType::Closed->value;
        $transferred = ConversationEventType::Transferred->value;
        $placeholders = implode(', ', array_fill(0, count($attention), '?'));

        /** @var object{first_assigned_at: string|null, resolved_at: string|null, last_closed_at: string|null, closed_count: int|string|null, reopened_count: int|string|null, transfers_count: int|string|null} $row */
        $row = DB::table('conversation_events')
            ->where('conversation_id', $conversationId)
            ->selectRaw("MIN(CASE WHEN type IN ({$placeholders}) OR (type = ? AND to_user_id IS NOT NULL) THEN created_at END) AS first_assigned_at", [...$attention, $transferred])
            ->selectRaw('MIN(CASE WHEN type = ? THEN created_at END) AS resolved_at', [$closed])
            ->selectRaw('MAX(CASE WHEN type = ? THEN created_at END) AS last_closed_at', [$closed])
            ->selectRaw('SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) AS closed_count', [$closed])
            ->selectRaw('SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) AS reopened_count', [ConversationEventType::Reopened->value])
            ->selectRaw('SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) AS transfers_count', [$transferred])
            ->first();

        return [
            'first_assigned_at' => $this->date($row->first_assigned_at),
            'resolved_at' => $this->date($row->resolved_at),
            'last_closed_at' => $this->date($row->last_closed_at),
            'closed_count' => (int) $row->closed_count,
            'reopened_count' => (int) $row->reopened_count,
            'transfers_count' => (int) $row->transfers_count,
        ];
    }

    /**
     * @return array{first_human_response_at: CarbonImmutable|null, messages_in: int, messages_out: int, failed_out: int}
     */
    private function messageFigures(int $conversationId): array
    {
        $outbound = MessageDirection::Outbound->value;
        $accepted = self::ACCEPTED_STATUSES;
        $placeholders = implode(', ', array_fill(0, count($accepted), '?'));
        $isAutomatic = "COALESCE(JSON_VALUE(metadata, '$.automatic'), 'false') IN ('true', '1')";

        /** @var object{first_human_response_at: string|null, messages_in: int|string|null, messages_out: int|string|null, failed_out: int|string|null} $row */
        $row = DB::table('messages')
            ->where('conversation_id', $conversationId)
            ->selectRaw(
                "MIN(CASE WHEN direction = ? AND sender_user_id IS NOT NULL AND status IN ({$placeholders}) AND NOT ({$isAutomatic}) THEN created_at END) AS first_human_response_at",
                [$outbound, ...$accepted],
            )
            ->selectRaw('SUM(CASE WHEN direction = ? THEN 1 ELSE 0 END) AS messages_in', [MessageDirection::Inbound->value])
            ->selectRaw('SUM(CASE WHEN direction = ? THEN 1 ELSE 0 END) AS messages_out', [$outbound])
            ->selectRaw('SUM(CASE WHEN direction = ? AND status = ? THEN 1 ELSE 0 END) AS failed_out', [$outbound, 'failed'])
            ->first();

        return [
            'first_human_response_at' => $this->date($row->first_human_response_at),
            'messages_in' => (int) $row->messages_in,
            'messages_out' => (int) $row->messages_out,
            'failed_out' => (int) $row->failed_out,
        ];
    }

    /**
     * Department that received the conversation: the origin of its first
     * transfer when it was transferred, otherwise its current one.
     */
    private function receivingDepartment(Conversation $conversation): ?Department
    {
        $firstTransfer = DB::table('conversation_events')
            ->where('conversation_id', $conversation->id)
            ->where('type', ConversationEventType::Transferred->value)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first(['from_department_id']);

        $departmentId = $firstTransfer !== null ? $firstTransfer->from_department_id : $conversation->department_id;

        return $departmentId === null ? null : Department::query()->find((int) $departmentId);
    }

    private function secondsBetween(CarbonInterface $from, ?CarbonInterface $to): ?int
    {
        if ($to === null) {
            return null;
        }

        return max(0, (int) $from->diffInSeconds($to, false));
    }

    private function date(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value);
    }
}
