<?php

namespace App\Reports;

/**
 * An additional worksheet of the workbook (e.g. a breakdown per department).
 * By default extra sheets only exist in the XLSX output; with `inPdf` they
 * are also printed as a section of the PDF, before or after the main table.
 */
final readonly class ReportSheet
{
    /**
     * @param  list<Column>  $columns
     * @param  iterable<array<string, mixed>>  $rows  Values keyed by column key.
     * @param  bool  $inPdf  Also print the sheet as a section of the PDF.
     * @param  bool  $beforeTable  In the PDF, print it before the main table.
     * @param  bool  $newPage  In the PDF, start the section on a new page.
     * @param  string|null  $description  Short explanation shown under the section title.
     */
    public function __construct(
        public string $title,
        public array $columns,
        public iterable $rows,
        public bool $inPdf = false,
        public bool $beforeTable = false,
        public bool $newPage = false,
        public ?string $description = null,
    ) {}
}
