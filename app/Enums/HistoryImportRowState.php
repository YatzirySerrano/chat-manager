<?php

namespace App\Enums;

/**
 * Execution state of one inventory row of a history import batch.
 */
enum HistoryImportRowState: string
{
    /** Not executed yet (or the batch is still being classified). */
    case Pending = 'pending';

    /** Its conversation was created. */
    case Done = 'done';

    /** Skipped: it was already imported (by this or an earlier batch). */
    case Duplicate = 'duplicate';

    /** Excluded by the admin: nothing was created. */
    case Skipped = 'skipped';

    /** It could not be created; the reason is in `error`. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Done => 'Creada',
            self::Duplicate => 'Ya importada antes',
            self::Skipped => 'No importada',
            self::Failed => 'Con error',
        };
    }
}
