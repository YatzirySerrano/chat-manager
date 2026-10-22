<?php

namespace App\Services\Meta;

use RuntimeException;

/**
 * A media file that breaks the size or type rules; it is never stored.
 */
class MediaRejected extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
