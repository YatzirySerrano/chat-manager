<?php

namespace App\Enums;

/**
 * File formats a report can be exported to. Thresholds come from
 * config/exports.php so each installation can tune them.
 */
enum ExportFormat: string
{
    case Xlsx = 'xlsx';
    case Pdf = 'pdf';

    public function label(): string
    {
        return match ($this) {
            self::Xlsx => 'Excel (.xlsx)',
            self::Pdf => 'PDF',
        };
    }

    public function extension(): string
    {
        return $this->value;
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Pdf => 'application/pdf',
        };
    }

    /**
     * Up to this many rows the file is generated during the request.
     */
    public function syncThreshold(): int
    {
        return (int) config("exports.formats.{$this->value}.sync_rows");
    }

    /**
     * Hard limit: larger exports are refused and must be narrowed with filters.
     */
    public function maxRows(): int
    {
        return (int) config("exports.formats.{$this->value}.max_rows");
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
