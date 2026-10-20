<?php

namespace App\Services\Meta;

use RuntimeException;

/**
 * A Graph API call that did not succeed. The message is always safe to store
 * and display: it never contains the access token or the raw response.
 */
class MetaGraphException extends RuntimeException
{
    /**
     * @param  bool  $rejected  Meta answered and refused the request (bad token, unknown ID, missing permission).
     *                          False when Meta could not be reached or failed on its side, which says nothing
     *                          about the credentials.
     */
    public function __construct(
        string $message,
        public readonly bool $rejected,
        public readonly ?int $metaCode = null,
        public readonly ?int $status = null,
    ) {
        parent::__construct($message);
    }
}
