<?php

namespace App\Reports;

/**
 * How a column value is stored and formatted in every output format.
 */
enum ColumnType: string
{
    case Text = 'text';
    case Number = 'number';
    case Date = 'date';
    case DateTime = 'datetime';
    case Boolean = 'boolean';
    case Currency = 'currency';
    case Percent = 'percent';

    public function defaultAlign(): Align
    {
        return match ($this) {
            self::Number, self::Currency, self::Percent => Align::Right,
            self::Boolean, self::Date, self::DateTime => Align::Center,
            self::Text => Align::Left,
        };
    }

    public function defaultDecimals(): int
    {
        return match ($this) {
            self::Currency => 2,
            self::Percent => 1,
            default => 0,
        };
    }

    public function isNumeric(): bool
    {
        return in_array($this, [self::Number, self::Currency, self::Percent], true);
    }
}
