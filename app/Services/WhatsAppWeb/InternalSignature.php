<?php

namespace App\Services\WhatsAppWeb;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;

/**
 * HMAC signature shared by Laravel and the WhatsApp Web service, in both
 * directions (docs/whatsapp-web-qr-contrato.md §2):
 *
 *   hex(HMAC_SHA256(secret, "{timestamp}.{nonce}.{METHOD}.{path}.{sha256_hex(body)}"))
 *
 * `path` includes the query string exactly as sent. A request is refused
 * when anything is missing, the signature differs (constant time), the
 * timestamp is more than 60 seconds away or the nonce was already seen in
 * the last 5 minutes (replay). Without a configured secret nothing is ever
 * signed or accepted: the modality is disabled.
 */
class InternalSignature
{
    public const MIN_SECRET_LENGTH = 32;

    public function __construct(private Repository $cache) {}

    public function secret(): ?string
    {
        $secret = (string) config('whatsapp_web.secret');

        return strlen($secret) >= self::MIN_SECRET_LENGTH ? $secret : null;
    }

    public function isConfigured(): bool
    {
        return $this->secret() !== null;
    }

    /**
     * Headers of a signed request to the service; null without a secret.
     *
     * @return array{X-CM-Timestamp: string, X-CM-Nonce: string, X-CM-Signature: string}|null
     */
    public function headers(string $method, string $path, string $body = ''): ?array
    {
        $secret = $this->secret();

        if ($secret === null) {
            return null;
        }

        $timestamp = (string) now()->getTimestamp();
        $nonce = (string) Str::uuid();

        return [
            'X-CM-Timestamp' => $timestamp,
            'X-CM-Nonce' => $nonce,
            'X-CM-Signature' => self::compute($secret, $timestamp, $nonce, $method, $path, $body),
        ];
    }

    /**
     * Query parameters (ts, nonce, sig) that sign a GET the service makes
     * later with no headers of ours (an outbound file, §6). `$path` is
     * signed without those three parameters.
     *
     * @return array{ts: string, nonce: string, sig: string}|null
     */
    public function query(string $method, string $path): ?array
    {
        $headers = $this->headers($method, $path);

        return $headers === null ? null : [
            'ts' => $headers['X-CM-Timestamp'],
            'nonce' => $headers['X-CM-Nonce'],
            'sig' => $headers['X-CM-Signature'],
        ];
    }

    /**
     * Why a received request is refused, or null when it is authentic:
     * disabled, missing, timestamp, signature or replay.
     */
    public function refusal(?string $timestamp, ?string $nonce, ?string $signature, string $method, string $path, string $body): ?string
    {
        $secret = $this->secret();

        if ($secret === null) {
            return 'disabled';
        }

        if ($timestamp === null || $nonce === null || $signature === null || $signature === ''
            || ! ctype_digit($timestamp) || preg_match('/^[A-Za-z0-9-]{8,64}$/', $nonce) !== 1) {
            return 'missing';
        }

        if (abs(now()->getTimestamp() - (int) $timestamp) > (int) config('whatsapp_web.signature.tolerance_seconds', 60)) {
            return 'timestamp';
        }

        if (! hash_equals(self::compute($secret, $timestamp, $nonce, $method, $path, $body), strtolower($signature))) {
            return 'signature';
        }

        // Only an authentic request spends its nonce: without the secret
        // nobody can burn the nonces of real requests.
        if (! $this->cache->add('whatsapp-web:nonce:'.$nonce, 1, (int) config('whatsapp_web.signature.nonce_ttl_seconds', 300))) {
            return 'replay';
        }

        return null;
    }

    public static function compute(string $secret, string $timestamp, string $nonce, string $method, string $path, string $body): string
    {
        return hash_hmac('sha256', implode('.', [$timestamp, $nonce, strtoupper($method), $path, hash('sha256', $body)]), $secret);
    }
}
