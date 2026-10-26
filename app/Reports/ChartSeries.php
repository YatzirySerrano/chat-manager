<?php

namespace App\Reports;

final readonly class ChartSeries
{
    /**
     * @param  list<int|float|null>  $values  One value per chart label, from real queries;
     *                                        null is a bucket without data (a gap in a line, never a 0).
     */
    public function __construct(
        public string $name,
        public array $values,
    ) {}
}
