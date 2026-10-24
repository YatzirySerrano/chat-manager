<?php

namespace App\Services\Operations;

use Illuminate\Support\Str;

/**
 * Turns technical text (exception messages, log lines) into something safe
 * to show to an administrator: only the first line, with access tokens,
 * credentials in URLs or key=value pairs, e-mail addresses and phone-like
 * digit runs masked, and bounded in length. Payloads are never passed here.
 */
final class SensitiveText
{
    private const MASK = '[oculto]';

    /**
     * @var array<string, string>
     */
    private const PATTERNS = [
        // Query exceptions append the SQL with its bindings (customer data).
        '/\s*\((Connection|SQL):.*$/s' => '',
        // Integration API tokens and web chat visitor tokens.
        '/cm_live_[a-z0-9]+_[A-Za-z0-9]+/' => self::MASK,
        '/wvs_[a-z0-9]+_[A-Za-z0-9]+/' => self::MASK,
        // Authorization headers and bearer tokens.
        '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i' => '$1 '.self::MASK,
        // key=value or "key":"value" pairs whose key names a secret.
        '/((?:access_token|app_secret|client_secret|secret|password|passwd|pwd|token|api_key|apikey|signature|verify_token|hub\.verify_token)["\']?\s*[:=]\s*["\']?)[^\s"\'&,;}]+/i' => '$1'.self::MASK,
        // Credentials embedded in URLs (scheme://user:pass@host).
        '#([a-z][a-z0-9+.-]*://)[^\s/@:]+:[^\s/@]+@#i' => '$1'.self::MASK.'@',
        // Meta access tokens (EAA...) and other long opaque strings.
        '/\bEAA[A-Za-z0-9]{20,}\b/' => self::MASK,
        '/\b[A-Za-z0-9_-]{40,}\b/' => self::MASK,
        // E-mail addresses.
        '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/' => '[correo]',
        // Phone numbers and other long digit runs (WhatsApp ids are phones).
        '/\+?\d[\d\s().-]{7,}\d/' => '[número]',
    ];

    public static function firstLine(?string $text, int $limit = 300): string
    {
        if ($text === null) {
            return '';
        }

        $line = trim((string) strtok(str_replace("\r", "\n", $text), "\n"));

        return self::scrub($line, $limit);
    }

    public static function scrub(string $text, int $limit = 300): string
    {
        $clean = (string) preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $text);

        return Str::limit((string) preg_replace('/\s+/', ' ', $clean), $limit);
    }
}
