<?php

namespace App\Services\Analytics;

use App\Enums\ConnectionChannel;
use App\Enums\ConversationStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

/**
 * Filters of the reports and the dashboard, already reduced to what the
 * viewer may use: a department, agent, connection, channel or category
 * outside the viewer's options is dropped (never revealed nor used), and a
 * personal reach has no department or agent filter at all.
 *
 * What each filter means, everywhere (screen, charts and exports):
 * - department, category, status: the CURRENT ones of the conversation;
 * - agent: the current (or last, once closed) responsible agent for the
 *   figures per conversation; the person's own row in the per-agent figures;
 * - connection / channel: where the conversation arrived;
 * - status does not apply to live snapshots nor to response cycles (the
 *   interface says so on those panels).
 */
final readonly class AnalyticsFilters
{
    public function __construct(
        public AnalyticsPeriod $period,
        public ?int $departmentId = null,
        public ?int $agentId = null,
        public ?int $connectionId = null,
        public ?int $categoryId = null,
        public ?string $status = null,
        public ?string $channel = null,
    ) {}

    /**
     * Statuses a viewer may filter by (the legacy on_hold counts as open).
     *
     * @return list<string>
     */
    public static function statuses(): array
    {
        return array_map(fn (ConversationStatus $status): string => $status->value, ConversationStatus::operational());
    }

    /**
     * Statuses offered by the filter, with their labels.
     *
     * @return list<array{id: string, name: string}>
     */
    public static function statusOptions(): array
    {
        return array_map(fn (ConversationStatus $status): array => ['id' => $status->value, 'name' => $status->label()], ConversationStatus::operational());
    }

    /**
     * Validation rules of the query string.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'period' => ['nullable', 'string', Rule::in(AnalyticsPeriod::PRESETS)],
            'from' => ['nullable', 'date_format:Y-m-d', 'required_if:period,custom'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_if:period,custom', 'after_or_equal:from'],
            'department' => ['nullable', 'integer', 'min:1'],
            'agent' => ['nullable', 'integer', 'min:1'],
            'connection' => ['nullable', 'integer', 'min:1'],
            'category' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', Rule::in(self::statuses())],
            'channel' => ['nullable', 'string', Rule::enum(ConnectionChannel::class)],
            'granularity' => ['nullable', 'string', Rule::in(AnalyticsPeriod::GRANULARITIES)],
        ];
    }

    /**
     * Build the filters from validated input, dropping unreachable values.
     *
     * @param  array<string, mixed>  $input
     */
    public static function from(array $input, User $viewer, AnalyticsScope $scope, string $timezone, ?CarbonImmutable $now = null): self
    {
        $preset = is_string($input['period'] ?? null) ? $input['period'] : AnalyticsPeriod::DEFAULT_PRESET;
        $from = is_string($input['from'] ?? null) ? $input['from'] : null;
        $to = is_string($input['to'] ?? null) ? $input['to'] : null;
        $period = AnalyticsPeriod::resolve($preset, $from, $to, $timezone, $now);

        if ($period->days() > AnalyticsPeriod::MAX_DAYS) {
            $period = new AnalyticsPeriod('custom', $period->to->subDays(AnalyticsPeriod::MAX_DAYS), $period->to, $timezone);
        }

        $status = is_string($input['status'] ?? null) && in_array($input['status'], self::statuses(), true) ? $input['status'] : null;
        $channel = is_string($input['channel'] ?? null) ? $input['channel'] : null;

        return new self(
            $period->withGrain(is_string($input['granularity'] ?? null) ? $input['granularity'] : null),
            self::reachable($input['department'] ?? null, $scope->departmentOptions($viewer)),
            self::reachable($input['agent'] ?? null, $scope->agentOptions($viewer)),
            self::reachable($input['connection'] ?? null, $scope->connectionOptions($viewer)),
            self::reachable($input['category'] ?? null, $scope->categoryOptions()),
            $status,
            in_array($channel, array_column($scope->channelOptions($viewer), 'id'), true) ? $channel : null,
        );
    }

    /**
     * Same filters on the whole previous period (aligned buckets of a trend).
     */
    public function previous(): self
    {
        return $this->on($this->period->previous());
    }

    /**
     * Same filters on the period the figures are compared against: the
     * previous one, cut to the elapsed portion while the current period is
     * still in progress (AnalyticsPeriod::comparison()).
     */
    public function comparison(): self
    {
        return $this->on($this->period->comparison());
    }

    /**
     * Same filters on another period.
     */
    public function on(AnalyticsPeriod $period): self
    {
        return new self($period, $this->departmentId, $this->agentId, $this->connectionId, $this->categoryId, $this->status, $this->channel);
    }

    /**
     * Same filters without the status (for figures it cannot apply to).
     */
    public function withoutStatus(): self
    {
        return new self($this->period, $this->departmentId, $this->agentId, $this->connectionId, $this->categoryId, null, $this->channel);
    }

    /**
     * Same filters without the agent (the per-agent figures list everyone
     * and apply the agent filter to their own rows).
     */
    public function withoutAgent(): self
    {
        return new self($this->period, $this->departmentId, null, $this->connectionId, $this->categoryId, $this->status, $this->channel);
    }

    /**
     * Query string values (only what differs from the defaults).
     *
     * @return array<string, string|int>
     */
    public function toQuery(): array
    {
        return array_filter([
            'period' => $this->period->preset === AnalyticsPeriod::DEFAULT_PRESET ? null : $this->period->preset,
            'from' => $this->period->preset === 'custom' ? $this->period->from->toDateString() : null,
            'to' => $this->period->preset === 'custom' ? $this->period->lastDay() : null,
            'department' => $this->departmentId,
            'agent' => $this->agentId,
            'connection' => $this->connectionId,
            'category' => $this->categoryId,
            'status' => $this->status,
            'channel' => $this->channel,
            'granularity' => $this->period->grain,
        ], fn (string|int|null $value): bool => $value !== null);
    }

    /**
     * Identity of the filters for cache keys.
     */
    public function cacheKey(): string
    {
        return implode(':', [
            $this->period->fromUtc()->getTimestamp(),
            $this->period->toUtc()->getTimestamp(),
            $this->departmentId ?? '-',
            $this->agentId ?? '-',
            $this->connectionId ?? '-',
            $this->categoryId ?? '-',
            $this->status ?? '-',
            $this->channel ?? '-',
            $this->period->grain ?? '-',
        ]);
    }

    /**
     * @param  list<array{id: int, name: string, ...}>  $options
     */
    private static function reachable(mixed $value, array $options): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $id = (int) $value;

        return in_array($id, array_column($options, 'id'), true) ? $id : null;
    }
}
