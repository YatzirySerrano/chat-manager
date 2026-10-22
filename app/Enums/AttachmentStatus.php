<?php

namespace App\Enums;

/**
 * Whether the file behind a message attachment can be served.
 */
enum AttachmentStatus: string
{
    /** Inbound media announced by Meta, not downloaded yet. */
    case Pending = 'pending';

    /** Stored on the private media disk. */
    case Available = 'available';

    /** It could not be obtained or was refused (see error_code). */
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Descargando archivo',
            self::Available => 'Disponible',
            self::Unavailable => 'Archivo no disponible',
        };
    }
}
