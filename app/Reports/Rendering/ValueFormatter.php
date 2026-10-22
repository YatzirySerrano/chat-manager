<?php

namespace App\Reports\Rendering;

use App\Reports\Column;
use App\Reports\ColumnType;
use Carbon\CarbonInterface;

/**
 * Presentation of report values as text (PDF, chart labels) using the
 * es-MX conventions and the platform time zone.
 */
final class ValueFormatter
{
    public function __construct(private string $timezone) {}

    public function format(mixed $value, Column $column): string
    {
        return $this->formatAs($value, $column->type, $column->decimalPlaces());
    }

    public function formatAs(mixed $value, ColumnType $type, ?int $decimals = null): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $decimals ??= $type->defaultDecimals();

        return match ($type) {
            ColumnType::Boolean => $value ? 'Sí' : 'No',
            ColumnType::Date => $value instanceof CarbonInterface ? $value->copy()->setTimezone($this->timezone)->format('d/m/Y') : (string) $value,
            ColumnType::DateTime => $value instanceof CarbonInterface ? $value->copy()->setTimezone($this->timezone)->format('d/m/Y H:i') : (string) $value,
            ColumnType::Number => is_numeric($value) ? number_format((float) $value, $decimals, '.', ',') : $this->text($value),
            ColumnType::Currency => is_numeric($value) ? '$'.number_format((float) $value, $decimals, '.', ',') : $this->text($value),
            ColumnType::Percent => is_numeric($value) ? number_format((float) $value * 100, $decimals, '.', ',').' %' : $this->text($value),
            ColumnType::Text => $this->text($value),
        };
    }

    private function text(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'Sí' : 'No',
            is_scalar($value) => (string) $value,
            $value instanceof CarbonInterface => $value->copy()->setTimezone($this->timezone)->format('d/m/Y H:i'),
            default => '',
        };
    }
}
