<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Messaging\StoreWebhookDelivery;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Services\Meta\MetaApp;
use App\Services\Meta\MetaMessagingPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * App-level webhook of the Meta app for Facebook Messenger (object "page")
 * and Instagram (object "instagram"): /webhooks/meta.
 *
 * - GET answers Meta's verification challenge when hub.verify_token equals
 *   services.meta.webhook_verify_token (constant-time comparison).
 * - POST rejects bodies over the size cap, authenticates the raw body with
 *   X-Hub-Signature-256 (HMAC-SHA256 with the secret of the payload's
 *   channel: the Facebook app secret for "page", the Instagram app secret
 *   for "instagram" when configured, else the Facebook one), splits it per account and stores each part for the
 *   connection whose external_account_id is the entry id
 *   (StoreWebhookDelivery: idempotent, processed on the webhooks queue).
 *   Entries of unknown, deleted or disconnected accounts are ignored with
 *   200, so Meta does not retry them.
 *
 * Refusals never explain why, so the endpoint cannot be probed. Failed
 * attempts (wrong verify token, invalid signature) are counted per source
 * address and only failed attempts are ever refused with 429 once
 * services.meta.webhook_invalid_signatures_per_minute is exceeded: the
 * signature (or token) is always checked first, so a correctly signed
 * delivery is never blocked by someone else's bad requests, even when every
 * request seems to come from the same proxy address. Signed deliveries only
 * face the generous per-address "meta-webhooks" flood limit.
 */
class MetaWebhookController extends Controller
{
    public function verify(Request $request, MetaApp $app): Response
    {
        $expected = (string) $app->webhookVerifyToken();
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($request->query('hub_mode') !== 'subscribe' || ! is_string($token) || $expected === '' || ! hash_equals($expected, $token)
            || ! is_string($challenge) || preg_match('/^[A-Za-z0-9_\-.]{1,256}$/', $challenge) !== 1) {
            if ($this->failedAttemptsExceeded('meta-webhook-verify-invalid:'.$request->ip())) {
                return $this->plain('', 429);
            }

            Log::warning('Meta app webhook verification refused.');

            return $this->plain('', 403);
        }

        return $this->plain($challenge, 200);
    }

    public function receive(Request $request, MetaApp $app, StoreWebhookDelivery $store): Response
    {
        $body = $request->getContent();

        if (strlen($body) > (int) config('whatsapp.webhooks.max_body_bytes')) {
            return $this->plain('', 413);
        }

        $secrets = $app->webhookSecrets();

        if ($secrets === []) {
            Log::warning('Meta app webhook refused: META_APP_SECRET is not configured.');

            return $this->plain('', 403);
        }

        $signature = (string) $request->header('X-Hub-Signature-256', '');
        $signedWith = null;

        foreach ($secrets as $secret) {
            if (hash_equals('sha256='.hash_hmac('sha256', $body, $secret), $signature)) {
                $signedWith = $secret;
            }
        }

        $payload = $signedWith === null ? null : json_decode($body, true);
        $channel = is_array($payload) ? MetaMessagingPayload::channelOf($payload['object'] ?? null) : null;

        // Each object must be signed with its own channel's secret: page
        // events with the Facebook app secret, Instagram Login events with
        // the Instagram app secret (or the Facebook one when no separate
        // Instagram secret is configured).
        if ($signedWith === null || ($channel !== null && ! hash_equals((string) $app->clientSecret($channel), $signedWith))) {
            if ($this->failedAttemptsExceeded('meta-webhook-invalid:'.$request->ip())) {
                return $this->plain('', 429);
            }

            Log::warning('Meta app webhook refused: invalid signature.');

            return $this->plain('', 401);
        }

        if (! is_array($payload)) {
            return $this->plain('', 400);
        }

        if ($channel === null) {
            Log::info('Meta app webhook ignored: object not handled.', ['object' => is_string($payload['object'] ?? null) ? substr($payload['object'], 0, 40) : null]);

            return $this->plain('', 200);
        }

        foreach (MetaMessagingPayload::accountIds($payload) as $accountId) {
            $connection = Connection::query()
                ->where('channel', $channel)
                ->where('external_account_id', $accountId)
                ->first();

            if ($connection === null || $connection->isDeleted() || blank($connection->access_token)) {
                Log::info('Meta app webhook entry ignored: no authorized connection for the account.', ['channel' => $channel->value, 'connection_id' => $connection?->id]);

                continue;
            }

            // Each account's part is stored on its own connection; the same
            // entry delivered again encodes identically and is stored once.
            $part = MetaMessagingPayload::forAccount($payload, $accountId);
            $store->handle($connection, (string) json_encode($part, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $part);
        }

        return $this->plain('', 200);
    }

    /**
     * Records one failed attempt for the address and tells whether its budget
     * (services.meta.webhook_invalid_signatures_per_minute) was already spent.
     * Only called after the credential was checked and found wrong, so valid
     * requests never consume or hit this budget.
     */
    private function failedAttemptsExceeded(string $key): bool
    {
        $limit = max(1, (int) config('services.meta.webhook_invalid_signatures_per_minute', 30));

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return true;
        }

        RateLimiter::hit($key, 60);

        return false;
    }

    private function plain(string $content, int $status): Response
    {
        return response($content, $status, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }
}
