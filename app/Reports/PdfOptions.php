<?php

namespace App\Reports;

/**
 * What the PDF version of a report includes.
 */
final readonly class PdfOptions
{
    public function __construct(
        public bool $includeMetrics = true,
        public bool $includeCharts = true,
        public bool $includeTable = true,
        public string $tableTitle = 'Detalle',
    ) {}
}
