<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Messaging\StoreWebhookDelivery;
use App\Enums\ConnectionChannel;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Public webhook of one Telegram bot connection:
 * /webhooks/telegram/{webhookKey}, where the key is a random opaque value
 * of the connection (ids are never exposed or enumerable).
 *
 * The request is authenticated by X-Telegram-Bot-Api-Secret-Token, the
 * secret_token given to setWebhook (constant-time comparison), stored and
 * answered 200 at once; processing runs on the webhooks queue
 * (StoreWebhookDelivery: a repeated update is stored once). Refusals never
 * explain why, so the endpoint cannot be probed.
 */
class TelegramWebhookController extends Controller
{
    public function receive(Request $request, string $webhookKey, StoreWebhookDelivery $store): Response
    {
        $connection = Connection::query()
            ->where('webhook_key', $webhookKey)
            ->where('channel', ConnectionChannel::Telegram)
            ->first();

        abort_if($connection === null || $connection->isDeleted(), 404);

        $body = $request->getContent();

        if (strlen($body) > (int) config('whatsapp.webhooks.max_body_bytes')) {
            return $this->plain('', 413);
        }

        $secret = (string) $connection->webhook_verify_token;
        $header = $request->header('X-Telegram-Bot-Api-Secret-Token');

        if ($secret === '' || ! is_string($header) || ! hash_equals($secret, $header)) {
            Log::warning('Telegram webhook refused: invalid secret token.', ['connection_id' => $connection->id]);

            return $this->plain('', 401);
        }

        $payload = json_decode($body, true);

        if (! is_array($payload) || ! is_int($payload['update_id'] ?? null)) {
            return $this->plain('', 400);
        }

        $store->handle($connection, $body, $payload);

        return $this->plain('', 200);
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
