<?php

namespace App\Enums;

/**
 * Progress of the copy of a chat file (video, image, PDF, document, audio) to the NAS (VideoExport).
 */
enum VideoExportStatus: string
{
    /** Waiting for the queue (also while waiting to retry after the NAS was down). */
    case Queued = 'queued';

    /** The job is copying the file. */
    case Saving = 'saving';

    /** The copy is complete and verified on the NAS. */
    case Saved = 'saved';

    /** The copy was given up; it may be retried by hand. */
    case Failed = 'failed';

    /**
     * Whether the copy is still on its way (a new one is refused meanwhile).
     */
    public function isPending(): bool
    {
        return $this === self::Queued || $this === self::Saving;
    }

    public function label(): string
    {
        return match ($this) {
            self::Queued, self::Saving => 'Guardando…',
            self::Saved => 'Guardado en Synology',
            self::Failed => 'Error al guardar',
        };
    }
}
