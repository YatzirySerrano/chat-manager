<?php

namespace App\Reports\Rendering;

use App\Reports\Chart;

/**
 * Pure geometry of the inline SVG charts of the PDF. Blade only prints the
 * shapes computed here, so charts are drawn from the real values with no
 * JavaScript and no network.
 *
 * @phpstan-type BarRow array{label: string, bars: list<array{y: float, width: float, color: string, value: string}>}
 * @phpstan-type BarGeometry array{width: int, height: int, labelWidth: int, plotWidth: int, rows: list<BarRow>, legend: list<array{name: string, color: string}>}
 * @phpstan-type DonutSegment array{label: string, value: string, percent: string, color: string, dash: string, offset: float}
 * @phpstan-type DonutGeometry array{size: int, radius: float, stroke: float, circumference: float, total: string, segments: list<DonutSegment>}
 * @phpstan-type LineSeries array{name: string, color: string, segments: list<string>, dots: list<array{x: float, y: float}>, last: array{x: float, y: float, value: string}|null}
 * @phpstan-type LineGeometry array{width: int, height: int, left: int, top: int, plotWidth: int, plotHeight: int, ticks: list<array{y: float, label: string}>, labels: list<array{x: float, label: string}>, series: list<LineSeries>}
 */
final class ChartGeometry
{
    private const BAR_HEIGHT = 14;

    private const ROW_GAP = 10;

    /**
     * Horizontal bars, one row per label (a ranking reads top to bottom).
     *
     * @return BarGeometry
     */
    public function bar(Chart $chart, int $width = 520): array
    {
        $labelWidth = 160;
        $valueWidth = 48;
        $plotWidth = $width - $labelWidth - $valueWidth;
        $seriesCount = max(1, count($chart->series));
        $rowHeight = $seriesCount * self::BAR_HEIGHT + ($seriesCount - 1) * 2 + self::ROW_GAP;
        $max = $this->maxValue($chart);
        $rows = [];

        foreach ($chart->labels as $index => $label) {
            $bars = [];

            foreach ($chart->series as $seriesIndex => $series) {
                $value = (float) ($series->values[$index] ?? 0);
                $bars[] = [
                    'y' => round($index * $rowHeight + $seriesIndex * (self::BAR_HEIGHT + 2), 2),
                    'width' => $max > 0 ? round(max($value > 0 ? 2 : 0, $value / $max * $plotWidth), 2) : 0,
                    'color' => $seriesCount === 1 ? ($chart->colorOf($index) ?? ReportPalette::ACCENT) : ReportPalette::series($seriesIndex),
                    'value' => $this->number($value),
                ];
            }

            $rows[] = ['label' => $this->truncate($label, 24), 'bars' => $bars];
        }

        return [
            'width' => $width,
            'height' => max(1, count($rows) * $rowHeight - self::ROW_GAP),
            'labelWidth' => $labelWidth,
            'plotWidth' => $plotWidth,
            'rows' => $rows,
            'legend' => $seriesCount > 1 ? $this->legend($chart) : [],
        ];
    }

    /**
     * Ring segments drawn with stroke dashes; a small gap separates them.
     *
     * @return DonutGeometry
     */
    public function donut(Chart $chart, int $size = 132): array
    {
        $values = array_map(fn (int|float|null $value): float => max(0, (float) $value), $chart->series[0]->values ?? []);
        $total = array_sum($values);
        $stroke = 20.0;
        $radius = ($size - $stroke) / 2;
        $circumference = 2 * M_PI * $radius;
        $count = count($chart->labels);
        $nonZero = count(array_filter($values, fn (float $value): bool => $value > 0));
        $gap = $nonZero > 1 ? 2.0 : 0.0;
        $segments = [];
        $offset = 0.0;

        foreach ($chart->labels as $index => $label) {
            $value = $values[$index] ?? 0.0;
            $length = $total > 0 ? $value / $total * $circumference : 0.0;
            $segments[] = [
                'label' => $label,
                'value' => $this->number($value),
                'percent' => $total > 0 ? number_format($value / $total * 100, 1, '.', ',').' %' : '0 %',
                'color' => $chart->colorOf($index) ?? ($count === 2 ? ($index === 0 ? ReportPalette::ACCENT : ReportPalette::NEUTRAL_MARK) : ReportPalette::series($index)),
                'dash' => round(max(0, $length - $gap), 3).' '.round($circumference, 3),
                'offset' => round(-$offset, 3),
            ];
            $offset += $length;
        }

        return [
            'size' => $size,
            'radius' => round($radius, 3),
            'stroke' => $stroke,
            'circumference' => round($circumference, 3),
            'total' => $this->number($total),
            'segments' => $segments,
        ];
    }

    /**
     * Lines over evenly spaced labels with a zero-based value axis. A null
     * value is a gap: the line stops there and starts again at the next
     * value (an isolated value is only a dot), so no bucket is invented.
     *
     * @return LineGeometry
     */
    public function line(Chart $chart, int $width = 520, int $height = 180): array
    {
        $left = 40;
        $top = 10;
        $plotWidth = $width - $left - 40;
        $plotHeight = $height - $top - 26;
        $max = $this->niceCeiling($this->maxValue($chart));
        $count = count($chart->labels);
        $step = $count > 1 ? $plotWidth / ($count - 1) : 0;
        $x = fn (int $index): float => round($left + ($count > 1 ? $index * $step : $plotWidth / 2), 2);
        $y = fn (float $value): float => round($top + $plotHeight - ($max > 0 ? $value / $max * $plotHeight : 0), 2);

        $ticks = [];

        for ($tick = 0; $tick <= 4; $tick++) {
            $value = $max / 4 * $tick;
            $ticks[] = ['y' => $y($value), 'label' => $this->number($value)];
        }

        $every = max(1, (int) ceil($count / 8));
        $labels = [];

        foreach ($chart->labels as $index => $label) {
            if ($index % $every === 0 || $index === $count - 1) {
                $labels[] = ['x' => $x($index), 'label' => $this->truncate($label, 12)];
            }
        }

        $series = [];

        foreach ($chart->series as $seriesIndex => $values) {
            $dots = [];
            $segments = [];
            $run = [];
            $last = null;

            foreach (array_keys($chart->labels) as $index) {
                $value = $values->values[$index] ?? null;

                if ($value === null) {
                    $segments[] = $run;
                    $run = [];

                    continue;
                }

                $dot = ['x' => $x($index), 'y' => $y((float) $value)];
                $dots[] = $dot;
                $run[] = "{$dot['x']},{$dot['y']}";
                $last = [...$dot, 'value' => $this->number((float) $value)];
            }

            $segments[] = $run;
            $series[] = [
                'name' => $values->name,
                'color' => count($chart->series) === 1 ? ReportPalette::ACCENT : ReportPalette::series($seriesIndex),
                'segments' => array_values(array_map(
                    fn (array $points): string => implode(' ', $points),
                    array_filter($segments, fn (array $points): bool => count($points) > 1),
                )),
                'dots' => $dots,
                'last' => $last,
            ];
        }

        return [
            'width' => $width,
            'height' => $height,
            'left' => $left,
            'top' => $top,
            'plotWidth' => $plotWidth,
            'plotHeight' => $plotHeight,
            'ticks' => $ticks,
            'labels' => $labels,
            'series' => $series,
        ];
    }

    /**
     * @return list<array{name: string, color: string}>
     */
    public function legend(Chart $chart): array
    {
        return array_map(fn (int $index): array => [
            'name' => $chart->series[$index]->name,
            'color' => ReportPalette::series($index),
        ], array_keys($chart->series));
    }

    private function maxValue(Chart $chart): float
    {
        $max = 0.0;

        foreach ($chart->series as $series) {
            foreach ($series->values as $value) {
                $max = max($max, (float) $value);
            }
        }

        return $max;
    }

    /**
     * Smallest "round" number (1, 2, 2.5, 5 × 10ⁿ) not below the value.
     */
    private function niceCeiling(float $value): float
    {
        if ($value <= 0) {
            return 1.0;
        }

        $magnitude = 10 ** floor(log10($value));

        foreach ([1, 2, 2.5, 5, 10] as $factor) {
            if ($factor * $magnitude >= $value) {
                return $factor * $magnitude;
            }
        }

        return 10 * $magnitude;
    }

    private function number(float $value): string
    {
        $decimals = abs($value - round($value)) < 0.05 ? 0 : 1;

        return number_format($value, $decimals, '.', ',');
    }

    private function truncate(string $text, int $length): string
    {
        return mb_strimwidth($text, 0, $length, '…');
    }
}
