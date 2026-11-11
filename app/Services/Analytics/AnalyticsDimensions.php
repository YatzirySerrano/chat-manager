<?php

namespace App\Services\Analytics;

use App\Enums\ConversationStatus;
use App\Models\Connection;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder;

/**
 * Applies the dimension filters to any table naming `connection_id`,
 * `department_id`, `category_id` and (optionally) `status`: the metrics, the
 * per-person rows, the live conversations and the response cycles. One
 * implementation, so a filter means the same in every figure.
 */
class AnalyticsDimensions
{
    /**
     * Connection ids per channel, resolved once per instance.
     *
     * @var array<string, list<int>>
     */
    private array $channelConnections = [];

    /**
     * @param  string|Expression|null  $agentColumn  Column the agent filter applies to (null = not applied).
     * @param  bool  $withStatus  False for tables without a conversation status (response cycles).
     */
    public function apply(Builder $query, AnalyticsFilters $filters, string|Expression|null $agentColumn, bool $withStatus = true): void
    {
        $query
            ->when($filters->departmentId !== null, fn (Builder $q) => $q->where('department_id', $filters->departmentId))
            ->when($filters->agentId !== null && $agentColumn !== null, fn (Builder $q) => $q->where($agentColumn, $filters->agentId))
            ->when($filters->connectionId !== null, fn (Builder $q) => $q->where('connection_id', $filters->connectionId))
            ->when($filters->categoryId !== null, fn (Builder $q) => $q->where('category_id', $filters->categoryId))
            ->when($filters->channel !== null, fn (Builder $q) => $q->whereIn('connection_id', $this->connectionsOf((string) $filters->channel)))
            // The legacy on_hold status counts as open.
            ->when($withStatus && $filters->status !== null, fn (Builder $q) => $q->whereIn('status', $filters->status === ConversationStatus::Open->value
                ? [ConversationStatus::Open->value, ConversationStatus::OnHold->value]
                : [$filters->status]));
    }

    /**
     * Connections of a channel (deleted ones included: they keep their history).
     *
     * @return list<int>
     */
    public function connectionsOf(string $channel): array
    {
        return $this->channelConnections[$channel] ??= array_values(array_map(
            fn (mixed $id): int => (int) $id,
            Connection::query()->where('channel', $channel)->toBase()->pluck('id')->all(),
        ));
    }
}
