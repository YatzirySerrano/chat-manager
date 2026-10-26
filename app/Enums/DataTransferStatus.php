<?php

namespace App\Enums;

/**
 * Lifecycle of an asynchronous file transfer.
 */
enum DataTransferStatus: string
{
    /** An uploaded import waiting for the user to map and confirm it. */
    case Draft = 'draft';
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Esperando tu confirmación',
            self::Queued => 'En espera',
            self::Processing => 'Generando',
            self::Completed => 'Listo',
            self::Failed => 'Falló',
            self::Expired => 'Archivo eliminado (venció)',
        };
    }

    /**
     * Whether the transfer is still waiting for or doing its work.
     */
    public function isPending(): bool
    {
        return $this === self::Queued || $this === self::Processing;
    }
}
