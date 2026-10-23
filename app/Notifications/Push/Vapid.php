<?php

namespace App\Notifications\Push;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * VAPID (RFC 8292) identification of this server before the browsers' push
 * services, with ext-openssl only: an EC P-256 key pair and a JWT signed
 * with ES256. Pushes carry no payload (see SendWebPush), so no message
 * encryption (aes128gcm) is involved.
 *
 * Keys use the usual Web Push format, base64url without padding: the
 * uncompressed public point (65 bytes, the browser's applicationServerKey)
 * and the private scalar (32 bytes).
 */
class Vapid
{
    /** OID 1.2.840.10045.3.1.7 (prime256v1), DER encoded. */
    private const P256_OID = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";

    /** Lifetime of a signed token (the protocol allows at most 24 hours). */
    private const TOKEN_TTL_SECONDS = 12 * 3600;

    private OpenSSLAsymmetricKey|false|null $privateKey = null;

    /**
     * A new key pair, to copy into VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY.
     *
     * @return array{public_key: string, private_key: string}
     */
    public static function generateKeys(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $details = $key === false ? false : openssl_pkey_get_details($key);

        if ($details === false || ! isset($details['ec']['d'], $details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('OpenSSL no pudo generar un par de claves P-256.');
        }

        return [
            'public_key' => self::base64UrlEncode("\x04".self::pad($details['ec']['x']).self::pad($details['ec']['y'])),
            'private_key' => self::base64UrlEncode(self::pad($details['ec']['d'])),
        ];
    }

    /**
     * Both keys are present, well formed and form a usable key.
     */
    public function isConfigured(): bool
    {
        return $this->publicKey() !== null && $this->key() !== false;
    }

    /**
     * The public key for applicationServerKey, or null when Web Push is off.
     */
    public function publicKey(): ?string
    {
        $key = config('webpush.public_key');
        $raw = is_string($key) ? self::base64UrlDecode($key) : null;

        return $raw !== null && strlen($raw) === 65 && $raw[0] === "\x04" ? $key : null;
    }

    /**
     * Value of the Authorization header for a push service endpoint:
     * `vapid t=<jwt>, k=<public key>`.
     */
    public function authorization(string $endpoint): string
    {
        $key = $this->key();
        $publicKey = $this->publicKey();

        if ($key === false || $publicKey === null) {
            throw new RuntimeException('Las claves VAPID no están configuradas.');
        }

        $parts = parse_url($endpoint);
        $scheme = $parts['scheme'] ?? 'https';
        $defaultPort = $scheme === 'http' ? 80 : 443;
        $audience = $scheme.'://'.($parts['host'] ?? '').(isset($parts['port']) && $parts['port'] !== $defaultPort ? ':'.$parts['port'] : '');

        $input = self::base64UrlEncode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']))
            .'.'.self::base64UrlEncode((string) json_encode([
                'aud' => $audience,
                'exp' => now()->addSeconds(self::TOKEN_TTL_SECONDS)->getTimestamp(),
                'sub' => (string) config('webpush.subject'),
            ], JSON_UNESCAPED_SLASHES));

        if (! openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('OpenSSL no pudo firmar el token VAPID.');
        }

        return 'vapid t='.$input.'.'.self::base64UrlEncode(self::derToRaw($der)).', k='.$publicKey;
    }

    /**
     * The private key as an OpenSSL key, built from the raw scalar (SEC1
     * ECPrivateKey in PEM), or false when unusable or when the configured
     * public key does not belong to it.
     */
    private function key(): OpenSSLAsymmetricKey|false
    {
        if ($this->privateKey !== null) {
            return $this->privateKey;
        }

        $private = config('webpush.private_key');
        $scalar = is_string($private) ? self::base64UrlDecode($private) : null;
        $public = $this->publicKey();

        if ($scalar === null || strlen($scalar) !== 32 || $public === null) {
            return false;
        }

        // Without the optional public key field OpenSSL derives it from the scalar.
        $der = "\x30\x31\x02\x01\x01\x04\x20".$scalar."\xa0\x0a".self::P256_OID;
        $pem = "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n";

        $key = openssl_pkey_get_private($pem);
        $details = $key === false ? false : openssl_pkey_get_details($key);

        // The public key must belong to the private one, or every push is refused.
        $matches = $details !== false && isset($details['ec']['x'], $details['ec']['y'])
            && "\x04".self::pad($details['ec']['x']).self::pad($details['ec']['y']) === self::base64UrlDecode($public);

        return $this->privateKey = $matches ? $key : false;
    }

    /**
     * An ECDSA signature from OpenSSL (DER SEQUENCE of two INTEGERs) as the
     * 64-byte r || s that JWS ES256 requires.
     */
    public static function derToRaw(string $der): string
    {
        if (strlen($der) < 8 || $der[0] !== "\x30" || $der[2] !== "\x02") {
            throw new RuntimeException('Firma ECDSA con formato inesperado.');
        }

        $offset = 3;
        $rLength = ord($der[$offset]);
        $r = substr($der, $offset + 1, $rLength);
        $offset += 1 + $rLength;

        if (($der[$offset] ?? '') !== "\x02") {
            throw new RuntimeException('Firma ECDSA con formato inesperado.');
        }

        $s = substr($der, $offset + 2, ord($der[$offset + 1]));

        return self::pad(ltrim($r, "\x00")).self::pad(ltrim($s, "\x00"));
    }

    private static function pad(string $bytes): string
    {
        return str_pad($bytes, 32, "\x00", STR_PAD_LEFT);
    }

    public static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
