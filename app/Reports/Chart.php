<?php

namespace App\Reports;

/**
 * A chart built from real query results. Rendered as inline SVG in the PDF
 * and as a native chart in the "Resumen" sheet of the workbook.
 */
final readonly class Chart
{
    /**
     * @param  list<string>  $labels  Categories (bar, donut) or points in time (line).
     * @param  list<ChartSeries>  $series  Donut charts use only the first series.
     */
    public function __construct(
        public ChartType $type,
        public string $title,
        public array $labels,
        public array $series,
        public ?string $subtitle = null,
    ) {}

    /**
     * Whether there is anything worth drawing.
     */
    public function hasData(): bool
    {
        foreach ($this->series as $series) {
            foreach ($series->values as $value) {
                if ($value != 0) {
                    return true;
                }
            }
        }

        return false;
    }
}
