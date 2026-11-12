<?php

namespace App\Services\WhatsAppWeb;

use RuntimeException;
use Throwable;

/**
 * A call to the WhatsApp Web service failed. `kind` says what is known
 * about the outcome, which decides what the caller may do:
 *
 * - DISABLED / UNREACHABLE: nothing left this server (no secret, or the
 *   service did not accept the connection);
 * - NOT_CONNECTED / UNKNOWN_SESSION / NOT_LINKED: the service answered that
 *   the session cannot do it right now; nothing was sent;
 * - REJECTED: the request is wrong for good (recipient, type, file);
 * - AMBIGUOUS: the request left and no answer says what happened (timeout
 *   or 504 send_timeout): a message may or may not have been sent;
 * - FAILED: the service answered an internal error.
 */
class WhatsAppWebException extends RuntimeException
{
    public const DISABLED = 'disabled';

    public const UNREACHABLE = 'unreachable';

    public const NOT_CONNECTED = 'not_connected';

    public const UNKNOWN_SESSION = 'unknown_session';

    public const NOT_LINKED = 'not_linked';

    public const REJECTED = 'rejected';

    public const AMBIGUOUS = 'ambiguous';

    public const FAILED = 'failed';

    public function __construct(
        string $message,
        public readonly string $kind,
        public readonly ?string $serviceError = null,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isAmbiguous(): bool
    {
        return $this->kind === self::AMBIGUOUS;
    }

    /**
     * Whether the service itself could not be used (as opposed to one session).
     */
    public function isServiceDown(): bool
    {
        return in_array($this->kind, [self::DISABLED, self::UNREACHABLE], true);
    }
}
