<?php

namespace App\Services\Analytics;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

/**
 * Filters of the reports and the dashboard, already reduced to what the
 * viewer may use: a department, agent or connection outside the viewer's
 * reach is dropped (never revealed nor used), and a personal reach has no
 * department or agent filter at all.
 */
final readonly class AnalyticsFilters
{
    public function __construct(
        public AnalyticsPeriod $period,
        public ?int $departmentId = null,
        public ?int $agentId = null,
        public ?int $connectionId = null,
    ) {}

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

        return new self(
            $period,
            self::reachable($input['department'] ?? null, $scope->departmentOptions($viewer)),
            self::reachable($input['agent'] ?? null, $scope->agentOptions($viewer)),
            self::reachable($input['connection'] ?? null, $scope->connectionOptions($viewer)),
        );
    }

    /**
     * Same filters on the previous period.
     */
    public function previous(): self
    {
        return new self($this->period->previous(), $this->departmentId, $this->agentId, $this->connectionId);
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
        ]);
    }

    /**
     * @param  list<array{id: int, name: string}>  $options
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
