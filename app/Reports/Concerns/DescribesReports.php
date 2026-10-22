<?php

namespace App\Reports\Concerns;

use App\Enums\RecordState;
use App\Reports\ReportContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared wording and figures of the report definitions: filter
 * descriptions, percentages and time series built from real queries.
 */
trait DescribesReports
{
    /**
     * "Búsqueda: «…»" when the report is searched.
     *
     * @param  list<string>  $descriptions
     */
    protected function describeSearch(array &$descriptions, ReportContext $context, string $key = 'search'): void
    {
        $search = $context->filter($key);

        if (is_string($search) && trim($search) !== '') {
            $descriptions[] = 'Búsqueda: «'.trim($search).'»';
        }
    }

    /**
     * The record state, only when it is not the default (active records).
     *
     * @param  list<string>  $descriptions
     */
    protected function describeRecordState(array &$descriptions, RecordState $state): void
    {
        if ($state !== RecordState::Active) {
            $descriptions[] = 'Estado del registro: '.$state->label();
        }
    }

    /**
     * "12.5 % del total", or null when there is no total.
     */
    protected function shareOf(int $part, int $total, string $suffix = 'del total'): ?string
    {
        return $total > 0 ? number_format($part / $total * 100, 1).' % '.$suffix : null;
    }

    /**
     * Records created per calendar month (platform time zone) for the last
     * months, oldest first, including months without records.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query  Already filtered and scoped.
     * @return array{labels: list<string>, values: list<int>}
     */
    protected function monthlySeries(Builder $query, ReportContext $context, int $months = 12): array
    {
        $now = CarbonImmutable::now($context->timezone)->startOfMonth();
        $periods = [];

        for ($back = $months - 1; $back >= 0; $back--) {
            $periods[] = $now->subMonthsNoOverflow($back);
        }

        $counts = (clone $query)
            ->withoutEagerLoads()
            ->reorder()
            ->where($query->qualifyColumn('created_at'), '>=', $periods[0]->utc())
            ->toBase()
            ->selectRaw("DATE_FORMAT(DATE_ADD(created_at, INTERVAL ? SECOND), '%Y-%m') AS period, COUNT(*) AS total", [$now->getOffset()])
            ->groupBy('period')
            ->pluck('total', 'period');

        return [
            'labels' => array_map(fn (CarbonImmutable $period): string => self::monthName($period->month).' '.$period->format('y'), $periods),
            'values' => array_map(fn (CarbonImmutable $period): int => (int) ($counts[$period->format('Y-m')] ?? 0), $periods),
        ];
    }

    /**
     * Short Spanish month name ("Ene" … "Dic").
     */
    protected static function monthName(int $month): string
    {
        return ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'][$month - 1] ?? (string) $month;
    }

    /**
     * The largest categories and the rest grouped as "Otros", largest first.
     *
     * @param  array<string, int>  $counts  Label => count.
     * @return array{labels: list<string>, values: list<int>}
     */
    protected function topCategories(array $counts, int $limit = 5, string $others = 'Otros'): array
    {
        $counts = array_filter($counts, fn (int $count): bool => $count > 0);
        arsort($counts);

        $top = array_slice($counts, 0, $limit, true);
        $rest = array_sum(array_slice($counts, $limit, null, true));

        if ($rest > 0) {
            $top[$others] = $rest;
        }

        return ['labels' => array_map('strval', array_keys($top)), 'values' => array_values($top)];
    }
}
