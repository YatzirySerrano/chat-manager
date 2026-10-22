<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;

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

    public function __construct(
        public string $preset,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $timezone,
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

        return new self(in_array($preset, self::PRESETS, true) ? $preset : self::DEFAULT_PRESET, $start, $end, $timezone);
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
     * @return 'day'|'month'
     */
    public function granularity(): string
    {
        return $this->days() <= self::DAILY_LIMIT_DAYS ? 'day' : 'month';
    }

    /**
     * UTC offset (seconds) of the time zone at the start of the period, used
     * to group timestamps by local day or hour in SQL.
     */
    public function offsetSeconds(): int
    {
        return $this->from->getOffset();
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
     * @return array{preset: string, from: string, to: string, label: string, timezone: string, granularity: 'day'|'month', previous: array{from: string, to: string, label: string}}
     */
    public function toArray(): array
    {
        $previous = $this->previous();

        return [
            'preset' => $this->preset,
            'from' => $this->from->toDateString(),
            'to' => $this->lastDay(),
            'label' => $this->label(),
            'timezone' => $this->timezone,
            'granularity' => $this->granularity(),
            'previous' => [
                'from' => $previous->from->toDateString(),
                'to' => $previous->lastDay(),
                'label' => $previous->label(),
            ],
        ];
    }
}
