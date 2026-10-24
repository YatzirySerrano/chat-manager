<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Messaging\StoreWebhookDelivery;
use App\Enums\ConnectionChannel;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Services\Meta\ConnectionMonitor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Public WhatsApp Cloud API webhook of one connection:
 * /webhooks/whatsapp/{webhookKey}, where the key is a random opaque value
 * of the connection (ids are never exposed or enumerable).
 *
 * - GET answers Meta's verification challenge when hub.verify_token equals
 *   the connection's verify token (constant-time comparison).
 * - POST authenticates the body with X-Hub-Signature-256 (HMAC-SHA256 of
 *   the raw body with the connection's app secret), stores it and answers
 *   200 immediately; processing runs on the webhooks queue.
 *
 * Refusals never explain why, so the endpoint cannot be probed.
 */
class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request, string $webhookKey, ConnectionMonitor $monitor): Response
    {
        $connection = $this->connection($webhookKey);
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');
        $expected = (string) $connection->webhook_verify_token;

        if ($mode !== 'subscribe' || ! is_string($token) || $expected === '' || ! hash_equals($expected, $token)
            || ! is_string($challenge) || preg_match('/^[A-Za-z0-9_\-.]{1,256}$/', $challenge) !== 1) {
            Log::warning('WhatsApp webhook verification refused.', ['connection_id' => $connection->id]);

            return $this->plain('', 403);
        }

        $monitor->webhookVerified($connection);

        return $this->plain($challenge, 200);
    }

    public function receive(Request $request, string $webhookKey, StoreWebhookDelivery $store): Response
    {
        $connection = $this->connection($webhookKey);
        $body = $request->getContent();

        if (strlen($body) > (int) config('whatsapp.webhooks.max_body_bytes')) {
            return $this->plain('', 413);
        }

        $secret = (string) $connection->app_secret;
        $signature = (string) $request->header('X-Hub-Signature-256', '');

        if ($secret === '') {
            Log::warning('WhatsApp webhook refused: the connection has no app secret to authenticate it.', ['connection_id' => $connection->id]);

            return $this->plain('', 403);
        }

        if (! hash_equals('sha256='.hash_hmac('sha256', $body, $secret), $signature)) {
            Log::warning('WhatsApp webhook refused: invalid signature.', ['connection_id' => $connection->id]);

            return $this->plain('', 401);
        }

        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return $this->plain('', 400);
        }

        $store->handle($connection, $body, $payload);

        return $this->plain('', 200);
    }

    private function connection(string $webhookKey): Connection
    {
        // Only WhatsApp connections have a webhook: a web chat connection's
        // key answers like an unknown one.
        $connection = Connection::query()
            ->where('webhook_key', $webhookKey)
            ->where('channel', ConnectionChannel::Whatsapp)
            ->first();

        abort_if($connection === null || $connection->isDeleted(), 404);

        return $connection;
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
