<?php

namespace App\Services\WebChat;

use App\Models\WebChatWidget;
use Illuminate\Support\Facades\Cache;

/**
 * Verifies the identities a host site signs for its signed-in users. The
 * host's backend computes
 *
 *     hex(HMAC-SHA256(identity_secret, "v1\n{public_key}\n{external_id}\n{email}\n{name}\n{iat}\n{nonce}"))
 *
 * (absent email or name are empty strings). A signature is accepted once:
 * `iat` must be within the tolerated clock skew and the nonce is consumed
 * atomically (Cache::add), so a captured identity cannot be replayed.
 *
 * The visitor id is derived from the connection and the host's external id,
 * so the same person is one contact per connection, never merged with a
 * WhatsApp identity or with another connection.
 */
class VisitorIdentity
{
    /**
     * @param  array<string, mixed>  $payload  {external_id, name?, email?, iat, nonce, signature}
     */
    public function verify(WebChatWidget $widget, array $payload): ?VerifiedIdentity
    {
        $externalId = $payload['external_id'] ?? null;
        $name = $payload['name'] ?? null;
        $email = $payload['email'] ?? null;
        $issuedAt = $payload['iat'] ?? null;
        $nonce = $payload['nonce'] ?? null;
        $signature = $payload['signature'] ?? null;
        $secret = $widget->identity_secret;

        if (! is_string($externalId) || $externalId === '' || mb_strlen($externalId) > 191
            || ($name !== null && ! is_string($name)) || ($email !== null && ! is_string($email))
            || ! is_int($issuedAt) || ! is_string($nonce) || preg_match('/^[A-Za-z0-9_-]{16,64}$/', $nonce) !== 1
            || ! is_string($signature) || preg_match('/^[0-9a-f]{64}$/', $signature) !== 1
            || ! is_string($secret) || $secret === '') {
            return null;
        }

        $expected = hash_hmac('sha256', implode("\n", ['v1', $widget->public_key, $externalId, $email ?? '', $name ?? '', (string) $issuedAt, $nonce]), $secret);

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        if (abs(now()->getTimestamp() - $issuedAt) > (int) config('webchat.identity.max_clock_skew_seconds', 300)) {
            return null;
        }

        $ttl = max(1, (int) config('webchat.identity.nonce_ttl_seconds', 600));

        if (! Cache::add('webchat:identity-nonce:'.$widget->id.':'.$nonce, true, $ttl)) {
            return null;
        }

        return new VerifiedIdentity(
            externalId: $externalId,
            visitorId: $this->visitorId($widget, $externalId),
            name: $name === '' ? null : $name,
            email: $email === '' ? null : $email,
        );
    }

    public function visitorId(WebChatWidget $widget, string $externalId): string
    {
        return 'wi:'.substr(hash('sha256', $widget->connection_id.'|'.$externalId), 0, 48);
    }
}
