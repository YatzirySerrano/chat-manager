<?php

namespace App\Actions\Connections;

/**
 * Result of checking the credentials of a connection against Meta.
 */
final readonly class CredentialVerification
{
    public const VERIFIED = 'verified';

    public const REJECTED = 'rejected';

    public const UNREACHABLE = 'unreachable';

    /**
     * @param  'verified'|'rejected'|'unreachable'  $result
     */
    public function __construct(
        public string $result,
        public string $message,
    ) {}

    public function verified(): bool
    {
        return $this->result === self::VERIFIED;
    }

    /**
     * Toast severity shown to the administrator.
     */
    public function tone(): string
    {
        return match ($this->result) {
            self::VERIFIED => 'success',
            self::REJECTED => 'error',
            default => 'warning',
        };
    }
}
