<?php

namespace App\Services\LinkPreviews;

use RuntimeException;

/**
 * A preview that may not or could not be fetched; "reason" is the short
 * code kept in link_previews.error (blocked_address, timeout, not_html...).
 */
class BlockedAddress extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Link preview refused: {$reason}");
    }
}
