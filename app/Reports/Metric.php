<?php

namespace App\Reports;

/**
 * A headline figure shown as a card in the PDF and in the "Resumen" sheet.
 * A null value means the figure is not available (never a fake zero).
 */
final readonly class Metric
{
    public function __construct(
        public string $label,
        public int|float|string|null $value,
        public ?string $hint = null,
        public ColumnType $type = ColumnType::Number,
    ) {}
}
