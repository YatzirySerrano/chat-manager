<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;

/**
 * Mutable state of one conversation while AgentAttributionCalculator walks
 * its events: the per-person rows and who is responsible at each moment.
 *
 * @phpstan-type AgentRow array{was_responsible: bool, responsible_now: bool, first_responsible_at: CarbonImmutable|null, attended_at: CarbonImmutable|null, handling_seconds: int, intervals_count: int, open_since: CarbonImmutable|null, replies_count: int, first_reply_at: CarbonImmutable|null, authored_first_response: bool, first_response_seconds: int|null, closes_count: int, last_closed_at: CarbonImmutable|null, transfers_in: int, transfers_out: int}
 */
final class AgentTimeline
{
    /**
     * @var array<int, AgentRow>
     */
    private array $rows = [];

    private ?int $current = null;

    private ?CarbonImmutable $since = null;

    /**
     * The person becomes responsible (a no-op when they already are).
     */
    public function start(int $userId, CarbonImmutable $at): void
    {
        if ($this->current === $userId) {
            return;
        }

        $this->stop($at);

        $row = $this->row($userId);
        $row['was_responsible'] = true;
        $row['first_responsible_at'] ??= $at;
        $row['intervals_count']++;
        $this->rows[$userId] = $row;
        $this->current = $userId;
        $this->since = $at;
    }

    /**
     * Nobody is responsible from this moment: the running interval ends.
     */
    public function stop(CarbonImmutable $at): void
    {
        if ($this->current !== null && $this->since !== null) {
            $row = $this->row($this->current);
            $row['handling_seconds'] += max(0, (int) $this->since->diffInSeconds($at, false));
            $this->rows[$this->current] = $row;
        }

        $this->current = null;
        $this->since = null;
    }

    /**
     * Human attention by the person (the earliest moment is kept).
     */
    public function attend(int $userId, CarbonImmutable $at): void
    {
        $row = $this->row($userId);

        if ($row['attended_at'] === null || $at->lessThan($row['attended_at'])) {
            $row['attended_at'] = $at;
        }

        $this->rows[$userId] = $row;
    }

    /**
     * The conversation was closed while the person was responsible.
     */
    public function close(int $userId, CarbonImmutable $at): void
    {
        $row = $this->row($userId);
        $row['was_responsible'] = true;
        $row['closes_count']++;
        $row['last_closed_at'] = $at;
        $this->rows[$userId] = $row;
    }

    public function transferOut(int $userId): void
    {
        $row = $this->row($userId);
        $row['transfers_out']++;
        $this->rows[$userId] = $row;
    }

    public function transferIn(int $userId): void
    {
        $row = $this->row($userId);
        $row['transfers_in']++;
        $this->rows[$userId] = $row;
    }

    /**
     * Human replies written by the person.
     */
    public function replied(int $userId, int $replies, CarbonImmutable $firstReplyAt): void
    {
        $this->attend($userId, $firstReplyAt);
        $row = $this->row($userId);
        $row['replies_count'] = $replies;
        $row['first_reply_at'] = $firstReplyAt;
        $this->rows[$userId] = $row;
    }

    /**
     * The person wrote the first human response of the conversation.
     */
    public function authoredFirstResponse(int $userId, int $seconds): void
    {
        $row = $this->row($userId);
        $row['authored_first_response'] = true;
        $row['first_response_seconds'] = $seconds;
        $this->rows[$userId] = $row;
    }

    /**
     * Close the walk against the state of the conversation itself: a
     * running interval only counts when the conversation confirms it (not
     * closed, assigned to that person); the assignee always gets a row.
     */
    public function settle(?int $assignee, bool $isClosed): void
    {
        if ($this->current !== null && $this->since !== null && ! $isClosed && $assignee === $this->current) {
            $row = $this->row($this->current);
            $row['open_since'] = $this->since;
            $this->rows[$this->current] = $row;
        }

        if ($assignee !== null) {
            $row = $this->row($assignee);
            $row['was_responsible'] = true;
            $row['responsible_now'] = ! $isClosed;
            $this->rows[$assignee] = $row;
        }
    }

    public function current(): ?int
    {
        return $this->current;
    }

    /**
     * @return array<int, AgentRow>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * @return AgentRow
     */
    private function row(int $userId): array
    {
        return $this->rows[$userId] ?? [
            'was_responsible' => false,
            'responsible_now' => false,
            'first_responsible_at' => null,
            'attended_at' => null,
            'handling_seconds' => 0,
            'intervals_count' => 0,
            'open_since' => null,
            'replies_count' => 0,
            'first_reply_at' => null,
            'authored_first_response' => false,
            'first_response_seconds' => null,
            'closes_count' => 0,
            'last_closed_at' => null,
            'transfers_in' => 0,
            'transfers_out' => 0,
        ];
    }
}
