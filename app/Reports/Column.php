<?php

namespace App\Reports;

/**
 * One column of a report table. Columns are fixed by each definition; the
 * client can never choose which attributes are exported.
 */
final readonly class Column
{
    /**
     * @param  string  $key  Key of the value in each mapped row.
     * @param  float|null  $width  Width in Excel character units (also used as the PDF proportion).
     * @param  float|null  $unbreakable  In the PDF, the value is printed on one line and the
     *                                   column is at least this many millimetres wide (e.g. phones).
     */
    public function __construct(
        public string $key,
        public string $label,
        public ColumnType $type = ColumnType::Text,
        public ?float $width = null,
        public ?Align $align = null,
        public ColumnTotal $total = ColumnTotal::None,
        public ?int $decimals = null,
        public ?float $unbreakable = null,
    ) {}

    public function alignment(): Align
    {
        return $this->align ?? $this->type->defaultAlign();
    }

    public function decimalPlaces(): int
    {
        return $this->decimals ?? $this->type->defaultDecimals();
    }

    public function excelWidth(): float
    {
        return $this->width ?? match ($this->type) {
            ColumnType::Text => 24,
            ColumnType::DateTime => 18,
            ColumnType::Date => 13,
            ColumnType::Boolean => 10,
            default => 13,
        };
    }
}
