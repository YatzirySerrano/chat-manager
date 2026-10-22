<?php

namespace App\Enums;

/**
 * Processing state of a stored webhook delivery.
 */
enum WebhookEventStatus: string
{
    /** Authenticated and stored; waiting for (or being retried by) a worker. */
    case Received = 'received';

    /** Every change of the body was handled. */
    case Processed = 'processed';

    /** The worker exhausted its attempts; it can be processed again. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Recibido',
            self::Processed => 'Procesado',
            self::Failed => 'Con error',
        };
    }
}
