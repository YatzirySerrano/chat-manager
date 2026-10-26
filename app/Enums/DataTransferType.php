<?php

namespace App\Enums;

/**
 * Direction of a file exchanged with the platform.
 */
enum DataTransferType: string
{
    case Export = 'export';
    case Import = 'import';

    /**
     * A Whaticket history import batch (HistoryImportBatches): classified
     * over days, so it keeps its own retention instead of the 24 hours of
     * the other transfers (DataTransfer::expired() leaves it out).
     */
    case HistoryImport = 'history_import';

    public function label(): string
    {
        return match ($this) {
            self::Export => 'Exportación',
            self::Import => 'Importación',
            self::HistoryImport => 'Importación de historial',
        };
    }
}
