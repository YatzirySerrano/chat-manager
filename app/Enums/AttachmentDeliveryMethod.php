<?php

namespace App\Enums;

/**
 * How an outbound attachment reaches the customer.
 */
enum AttachmentDeliveryMethod: string
{
    /** Sent as a file the channel supports natively. */
    case Native = 'native';

    /** The channel cannot carry the file type: sent as a secure expiring download link. */
    case Link = 'link';

    public function label(): string
    {
        return match ($this) {
            self::Native => 'Archivo adjunto',
            self::Link => 'Enlace de descarga',
        };
    }
}
