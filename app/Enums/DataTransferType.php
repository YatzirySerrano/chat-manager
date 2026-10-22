<?php

namespace App\Enums;

/**
 * Direction of a file exchanged with the platform.
 */
enum DataTransferType: string
{
    case Export = 'export';
    case Import = 'import';

    public function label(): string
    {
        return match ($this) {
            self::Export => 'Exportación',
            self::Import => 'Importación',
        };
    }
}
