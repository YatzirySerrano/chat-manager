<?php

namespace App\Reports;

/**
 * A block of label/value pairs describing one record (e.g. the header of a
 * conversation dossier). Rendered as a card in the PDF and as a two-column
 * table in the "Resumen" sheet of the workbook.
 */
final readonly class ReportPanel
{
    /**
     * @param  array<string, string|null>  $items  Label => already formatted value (null shows "—").
     */
    public function __construct(
        public string $title,
        public array $items,
        public ?string $note = null,
    ) {}
}
