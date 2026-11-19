<?php

namespace App\Services\Telegram;

use RuntimeException;

/**
 * A Bot API call that did not succeed. The message is always safe to store
 * and display: the bot token travels in the URL of every call, so neither
 * the URL nor the transport's own message is ever kept here.
 */
class TelegramException extends RuntimeException
{
    /** Telegram could not be reached: the request never left this server (DNS, refused, connect timeout). */
    public const UNREACHABLE = 'unreachable';

    /** The request may have reached Telegram but no usable answer arrived. */
    public const AMBIGUOUS = 'ambiguous';

    /** Too many requests (429); retry_after says when to try again. */
    public const RATE_LIMITED = 'rate_limited';

    /** Telegram failed on its side (5xx with an error body). */
    public const TRANSIENT = 'transient';

    /** The bot token is invalid or was revoked in BotFather (401, 404). */
    public const UNAUTHORIZED = 'unauthorized';

    /** The person blocked the bot or deleted their account (403). */
    public const FORBIDDEN = 'forbidden';

    /** The chat does not exist for this bot (400 "chat not found"). */
    public const CHAT_NOT_FOUND = 'chat_not_found';

    /** The file is over the 20 MB the Bot API lets bots download. */
    public const FILE_TOO_BIG = 'file_too_big';

    /** Any other refusal (400). */
    public const REJECTED = 'rejected';

    public function __construct(
        string $message,
        public readonly string $kind,
        public readonly ?int $status = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Whether repeating the same request later may succeed.
     */
    public function isRetryable(): bool
    {
        return in_array($this->kind, [self::UNREACHABLE, self::RATE_LIMITED, self::TRANSIENT], true);
    }
}
