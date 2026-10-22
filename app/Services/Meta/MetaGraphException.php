<?php

namespace App\Services\Meta;

use RuntimeException;

/**
 * A Graph API call that did not succeed. The message is always safe to store
 * and display: it never contains the access token or the raw response.
 */
class MetaGraphException extends RuntimeException
{
    /** Meta could not be reached at all: the request never left this server (DNS, refused, connect timeout). */
    public const UNREACHABLE = 'unreachable';

    /** The request may have reached Meta but no answer arrived (response timeout, gateway error without body). */
    public const AMBIGUOUS = 'ambiguous';

    /** Meta throttled the request (HTTP 429 or a throttling code); it was not processed. */
    public const RATE_LIMITED = 'rate_limited';

    /** Meta answered with a temporary failure of its own; the request was not processed. */
    public const TRANSIENT = 'transient';

    /** The access token is invalid, expired or revoked (codes 0/190, HTTP 401). */
    public const TOKEN_INVALID = 'token_invalid';

    /** The token lacks a permission or the asset is not shared with it. */
    public const PERMISSION = 'permission';

    /** An identifier does not exist or the request is malformed. */
    public const INVALID_REQUEST = 'invalid_request';

    /** WhatsApp refused this particular message (131xxx, 132xxx, ...). */
    public const MESSAGE_REJECTED = 'message_rejected';

    /** Any other refusal. */
    public const REJECTED = 'rejected';

    /**
     * @param  bool  $rejected  Meta answered and refused the request (bad token, unknown ID, missing permission).
     *                          False when Meta could not be reached or failed on its side, which says nothing
     *                          about the credentials.
     * @param  string  $kind  One of the constants above.
     */
    public function __construct(
        string $message,
        public readonly bool $rejected,
        public readonly ?int $metaCode = null,
        public readonly ?int $status = null,
        public readonly string $kind = self::REJECTED,
    ) {
        parent::__construct($message);
    }

    /**
     * Whether it is proven that Meta did not process the request, so
     * repeating it cannot duplicate its effect.
     */
    public function provenNotProcessed(): bool
    {
        return in_array($this->kind, [self::UNREACHABLE, self::RATE_LIMITED, self::TRANSIENT], true)
            || $this->rejected;
    }

    /**
     * Whether repeating the same request later may succeed.
     */
    public function isRetryable(): bool
    {
        return in_array($this->kind, [self::UNREACHABLE, self::RATE_LIMITED, self::TRANSIENT], true);
    }

    /**
     * The outcome is unknown: Meta may have processed the request.
     */
    public function isAmbiguous(): bool
    {
        return $this->kind === self::AMBIGUOUS;
    }

    /**
     * The connection's credentials themselves are at fault.
     */
    public function affectsCredentials(): bool
    {
        return in_array($this->kind, [self::TOKEN_INVALID, self::PERMISSION], true);
    }
}
