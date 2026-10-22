<?php

namespace App\Reports;

final readonly class ChartSeries
{
    /**
     * @param  list<int|float>  $values  One value per chart label, from real queries.
     */
    public function __construct(
        public string $name,
        public array $values,
    ) {}
}
