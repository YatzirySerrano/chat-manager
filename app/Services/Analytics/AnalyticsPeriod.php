<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * A reporting period in the platform time zone: [from, to) plus the
 * previous period of the same length, used for comparisons.
 */
final readonly class AnalyticsPeriod
{
    public const PRESETS = ['today', 'yesterday', '7d', '30d', '90d', 'month', 'previous_month', 'custom'];

    public const DEFAULT_PRESET = '30d';

    /**
     * Longest custom range accepted, in days.
     */
    public const MAX_DAYS = 366;

    /**
     * Ranges up to this many days are charted per day, longer ones per month.
     */
    public const DAILY_LIMIT_DAYS = 92;

    /**
     * Granularities a viewer may ask for (ISO weeks, Monday first).
     */
    public const GRANULARITIES = ['day', 'week', 'month'];

    /**
     * @param  CarbonImmutable|null  $asOf  Moment the period was resolved at; while it falls inside the
     *                                      period, the period is still in progress (see comparison()).
     * @param  string|null  $grain  Granularity asked by the viewer (null = automatic).
     */
    public function __construct(
        public string $preset,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $timezone,
        public ?CarbonImmutable $asOf = null,
        public ?string $grain = null,
    ) {}

    /**
     * Resolve a preset (or a custom range of local dates) relative to now.
     */
    public static function resolve(string $preset, ?string $from, ?string $to, string $timezone, ?CarbonImmutable $now = null): self
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone($timezone);
        $today = $now->startOfDay();

        [$start, $end] = match ($preset) {
            'today' => [$today, $today->addDay()],
            'yesterday' => [$today->subDay(), $today],
            '7d' => [$today->subDays(6), $today->addDay()],
            '90d' => [$today->subDays(89), $today->addDay()],
            'month' => [$today->startOfMonth(), $today->addDay()],
            'previous_month' => [$today->startOfMonth()->subMonthNoOverflow(), $today->startOfMonth()],
            'custom' => self::customRange($from, $to, $timezone, $today),
            default => [$today->subDays(29), $today->addDay()],
        };

        return new self(in_array($preset, self::PRESETS, true) ? $preset : self::DEFAULT_PRESET, $start, $end, $timezone, $now);
    }

    /**
     * The same period charted with another granularity.
     */
    public function withGrain(?string $grain): self
    {
        return new self($this->preset, $this->from, $this->to, $this->timezone, $this->asOf, in_array($grain, self::GRANULARITIES, true) ? $grain : null);
    }

    /**
     * Whether the period has not finished yet at the moment it was resolved.
     */
    public function inProgress(): bool
    {
        return $this->asOf !== null && $this->asOf->greaterThan($this->from) && $this->asOf->lessThan($this->to);
    }

    /**
     * The period the figures are compared against. A finished period is
     * compared with the whole previous one; a period still in progress is
     * compared with the same elapsed portion of the previous one (like
     * with like), never with a complete period.
     */
    public function comparison(): self
    {
        $previous = $this->previous();

        if (! $this->inProgress() || $this->asOf === null) {
            return $previous;
        }

        $elapsed = (int) $this->from->diffInSeconds($this->asOf, false);
        $end = $previous->from->addSeconds($elapsed);

        return new self($this->preset, $previous->from, $end->greaterThan($previous->to) ? $previous->to : $end, $this->timezone);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function customRange(?string $from, ?string $to, string $timezone, CarbonImmutable $today): array
    {
        $start = $from !== null ? CarbonImmutable::createFromFormat('!Y-m-d', $from, $timezone) : null;
        $end = $to !== null ? CarbonImmutable::createFromFormat('!Y-m-d', $to, $timezone) : null;
        $start = $start instanceof CarbonImmutable ? $start : $today->subDays(29);
        $end = ($end instanceof CarbonImmutable ? $end : $today)->addDay();

        if ($end <= $start) {
            $end = $start->addDay();
        }

        return [$start, $end];
    }

    public function fromUtc(): CarbonImmutable
    {
        return $this->from->utc();
    }

    public function toUtc(): CarbonImmutable
    {
        return $this->to->utc();
    }

    public function days(): int
    {
        return max(1, (int) round($this->from->diffInDays($this->to)));
    }

    /**
     * The period of the same length right before this one.
     */
    public function previous(): self
    {
        if ($this->preset === 'previous_month') {
            return new self($this->preset, $this->from->subMonthNoOverflow(), $this->from, $this->timezone);
        }

        if ($this->preset === 'month') {
            $start = $this->from->subMonthNoOverflow();
            $end = $start->addDays($this->days());

            return new self($this->preset, $start, $end > $this->from ? $this->from : $end, $this->timezone);
        }

        return new self($this->preset, $this->from->subDays($this->days()), $this->from, $this->timezone);
    }

    /**
     * Granularity of the charts: the one asked by the viewer when the
     * period allows it (days only up to DAILY_LIMIT_DAYS, so the payload
     * stays bounded), otherwise per day for short ranges and per month for
     * long ones.
     *
     * @return 'day'|'week'|'month'
     */
    public function granularity(): string
    {
        $daily = $this->days() <= self::DAILY_LIMIT_DAYS;

        return match ($this->grain) {
            'day' => $daily ? 'day' : 'week',
            'week' => 'week',
            'month' => 'month',
            default => $daily ? 'day' : 'month',
        };
    }

    /**
     * Granularities the viewer may choose for this period.
     *
     * @return list<'day'|'week'|'month'>
     */
    public function granularities(): array
    {
        return $this->days() <= self::DAILY_LIMIT_DAYS ? ['day', 'week', 'month'] : ['week', 'month'];
    }

    /**
     * Keys and labels of the buckets of the period, in order: local days
     * ("2026-10-21"), ISO weeks starting on Monday ("2026-W43", labelled by
     * the first day of the week inside the period) or months ("2026-10").
     *
     * @param  'day'|'week'|'month'  $granularity
     * @return list<array{0: string, 1: string}>
     */
    public function buckets(string $granularity): array
    {
        $months = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
        $buckets = [];
        $cursor = match ($granularity) {
            'day' => $this->from,
            'week' => $this->from->startOfWeek(CarbonImmutable::MONDAY),
            'month' => $this->from->startOfMonth(),
        };

        while ($cursor < $this->to) {
            $buckets[] = match ($granularity) {
                'day' => [$cursor->toDateString(), $cursor->day.' '.$months[$cursor->month - 1]],
                'week' => [$cursor->format('o-\\WW'), 'sem '.$cursor->day.' '.$months[$cursor->month - 1]],
                'month' => [$cursor->format('Y-m'), $months[$cursor->month - 1].' '.$cursor->format('y')],
            };
            $cursor = match ($granularity) {
                'day' => $cursor->addDay(),
                'week' => $cursor->addWeek(),
                'month' => $cursor->addMonthNoOverflow(),
            };
        }

        return $buckets;
    }

    /**
     * SQL expression of the bucket key of a local time expression, matching
     * the keys of buckets().
     *
     * @param  literal-string  $local
     * @param  'hour'|'day'|'week'|'month'  $granularity
     * @return literal-string
     */
    public static function bucketSql(string $local, string $granularity): string
    {
        $format = match ($granularity) {
            'hour' => '%Y-%m-%dT%H:00',
            'day' => '%Y-%m-%d',
            // ISO 8601: year of the week and week number, Monday first.
            'week' => '%x-W%v',
            'month' => '%Y-%m',
        };

        return "DATE_FORMAT({$local}, '{$format}')";
    }

    /**
     * SQL expression turning a stored UTC timestamp column into local time,
     * with the UTC offset in force at each moment of the period (daylight
     * saving changes inside the period included), plus its bindings. It
     * needs no time zone tables in the database: the offsets come from PHP.
     *
     * @param  literal-string  $column
     * @return array{0: literal-string, 1: list<int|string>}
     */
    public function localTimeSql(string $column): array
    {
        $transitions = (new DateTimeZone($this->timezone))->getTransitions($this->from->getTimestamp(), $this->to->getTimestamp());
        $offsets = [];
        $bindings = [];

        foreach (array_slice($transitions, 1) as $transition) {
            $offsets[] = "WHEN {$column} < ? THEN ?";
            $bindings[] = CarbonImmutable::createFromTimestampUTC($transition['ts'])->format('Y-m-d H:i:s');
            $bindings[] = $this->offsetBefore($transition['ts']);
        }

        $last = $this->to->subSecond()->getOffset();

        if ($offsets === []) {
            return ["DATE_ADD({$column}, INTERVAL ? SECOND)", [$last]];
        }

        return ['DATE_ADD('.$column.', INTERVAL CASE '.implode(' ', $offsets).' ELSE ? END SECOND)', [...$bindings, $last]];
    }

    private function offsetBefore(int $timestamp): int
    {
        return CarbonImmutable::createFromTimestampUTC($timestamp - 1)->setTimezone($this->timezone)->getOffset();
    }

    /**
     * Last local day included, as Y-m-d.
     */
    public function lastDay(): string
    {
        return $this->to->subDay()->toDateString();
    }

    public function label(): string
    {
        $first = self::localDate($this->from);
        $last = self::localDate($this->to->subDay());

        return $first === $last ? $first : "{$first} – {$last}";
    }

    /**
     * "5 oct 2026".
     */
    public static function localDate(CarbonImmutable $date): string
    {
        $months = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

        return $date->day.' '.$months[$date->month - 1].' '.$date->year;
    }

    /**
     * @return array{preset: string, from: string, to: string, label: string, timezone: string, granularity: 'day'|'week'|'month', granularities: list<'day'|'week'|'month'>, previous: array{from: string, to: string, label: string}, comparison: array{label: string, partial: bool, until: string|null}}
     */
    public function toArray(): array
    {
        $previous = $this->previous();
        $comparison = $this->comparison();
        $partial = $this->inProgress();

        return [
            'preset' => $this->preset,
            'from' => $this->from->toDateString(),
            'to' => $this->lastDay(),
            'label' => $this->label(),
            'timezone' => $this->timezone,
            'granularity' => $this->granularity(),
            'granularities' => $this->granularities(),
            'previous' => [
                'from' => $previous->from->toDateString(),
                'to' => $previous->lastDay(),
                'label' => $previous->label(),
            ],
            // What the figures are really compared against (see comparison()).
            'comparison' => [
                'label' => $partial
                    ? self::localDate($comparison->from).' – '.self::localDate($comparison->to).', '.$comparison->to->format('H:i')
                    : $previous->label(),
                'partial' => $partial,
                'until' => $partial ? $comparison->to->format('H:i') : null,
            ],
        ];
    }
}
