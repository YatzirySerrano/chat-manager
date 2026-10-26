<?php

namespace App\Enums;

/**
 * Reading state of a file uploaded to a history import batch.
 */
enum HistoryImportFileStatus: string
{
    case Uploaded = 'uploaded';
    case Parsing = 'parsing';
    case Parsed = 'parsed';
    case Failed = 'failed';

    /** The stored copy was deleted (batch finished or expired). */
    case Purged = 'purged';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'En espera de lectura',
            self::Parsing => 'Leyendo',
            self::Parsed => 'Leído',
            self::Failed => 'No se pudo leer',
            self::Purged => 'Archivo eliminado',
        };
    }
}
