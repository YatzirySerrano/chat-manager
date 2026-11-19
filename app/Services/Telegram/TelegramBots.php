<?php

namespace App\Services\Telegram;

use App\Models\Connection;
use App\Services\Meta\ConnectionMonitor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The webhook of a Telegram bot connection:
 *
 * - connect(): setWebhook to {base}/webhooks/telegram/{webhook_key} with a
 *   new random secret_token (stored encrypted only once Telegram accepted
 *   it), so every update can be authenticated by its
 *   X-Telegram-Bot-Api-Secret-Token header (TelegramWebhookController);
 * - disconnect(): deleteWebhook (a deactivated or deleted connection);
 *   Telegram keeps undelivered updates for up to 24 hours, so a bot
 *   reactivated soon still receives them;
 * - status(): getWebhookInfo for the connection's «Estado» section (cached
 *   briefly; never the token or the secret).
 *
 * Failures are recorded on the connection's health (ConnectionMonitor), with
 * a token Telegram rejects marked as invalid credentials.
 */
class TelegramBots
{
    /** Updates the bot asks Telegram for. */
    public const ALLOWED_UPDATES = ['message', 'edited_message', 'callback_query'];

    private const STATUS_CACHE_SECONDS = 30;

    public function __construct(private TelegramBotClient $client, private ConnectionMonitor $monitor) {}

    public function webhookUrl(Connection $connection): string
    {
        // Never the current request's scheme or host: Telegram only calls HTTPS.
        $base = rtrim((string) (config('services.telegram.webhook_url') ?: config('app.url')), '/');

        return $base.'/webhooks/telegram/'.$connection->webhook_key;
    }

    /**
     * Registers the webhook. Returns null when Telegram accepted it, or the
     * Spanish reason it did not (also kept as the connection's last error).
     */
    public function connect(Connection $connection): ?string
    {
        $token = (string) $connection->access_token;

        if (! $connection->isTelegram() || $token === '') {
            return 'La conexión no tiene guardado el token del bot.';
        }

        $secret = Str::random(64);

        try {
            $this->client->setWebhook($token, $this->webhookUrl($connection), $secret, self::ALLOWED_UPDATES);
        } catch (TelegramException $exception) {
            $this->forgetStatus($connection);

            return $this->failed($connection, $exception, 'No se pudo registrar el webhook en Telegram: ');
        }

        $connection->forceFill(['webhook_verify_token' => $secret, 'webhook_verified_at' => now()])->save();
        $this->forgetStatus($connection);

        return null;
    }

    /**
     * Removes the webhook; a failure is only logged (the connection already
     * refuses to send, and updates of a deleted one are discarded).
     */
    public function disconnect(Connection $connection): void
    {
        $token = (string) $connection->access_token;

        if (! $connection->isTelegram() || $token === '') {
            return;
        }

        try {
            $this->client->deleteWebhook($token);
            $connection->forceFill(['webhook_verified_at' => null])->save();
        } catch (TelegramException $exception) {
            Log::warning('Telegram webhook could not be removed.', ['connection_id' => $connection->id, 'kind' => $exception->kind]);
        }

        $this->forgetStatus($connection);
    }

    /**
     * What Telegram says about the webhook right now.
     *
     * @return array{checked: bool, registered: bool, url_matches: bool, pending_update_count: int, last_error_message: string|null, last_error_at: string|null, error: string|null}
     */
    public function status(Connection $connection): array
    {
        return Cache::remember($this->statusKey($connection), self::STATUS_CACHE_SECONDS, function () use ($connection): array {
            $empty = ['checked' => false, 'registered' => false, 'url_matches' => false, 'pending_update_count' => 0, 'last_error_message' => null, 'last_error_at' => null, 'error' => null];
            $token = (string) $connection->access_token;

            if ($token === '') {
                return $empty;
            }

            try {
                $info = $this->client->getWebhookInfo($token);
            } catch (TelegramException $exception) {
                return [...$empty, 'error' => $exception->getMessage()];
            }

            $url = is_string($info['url'] ?? null) ? $info['url'] : '';

            return [
                'checked' => true,
                'registered' => $url !== '',
                'url_matches' => $url !== '' && $url === $this->webhookUrl($connection),
                'pending_update_count' => is_int($info['pending_update_count'] ?? null) ? $info['pending_update_count'] : 0,
                'last_error_message' => is_string($info['last_error_message'] ?? null) ? Str::limit(strip_tags($info['last_error_message']), 200, '') : null,
                'last_error_at' => is_int($info['last_error_date'] ?? null) ? now()->setTimestamp($info['last_error_date'])->toIso8601String() : null,
                'error' => null,
            ];
        });
    }

    public function forgetStatus(Connection $connection): void
    {
        Cache::forget($this->statusKey($connection));
    }

    private function statusKey(Connection $connection): string
    {
        return 'telegram:webhook-status:'.$connection->id;
    }

    private function failed(Connection $connection, TelegramException $exception, string $prefix): string
    {
        $message = $prefix.$exception->getMessage();

        if ($exception->kind === TelegramException::UNAUTHORIZED) {
            $this->monitor->credentialsRejected($connection, $exception->getMessage());
        } else {
            $this->monitor->failure($connection, $message);
        }

        Log::warning('Telegram webhook could not be registered.', ['connection_id' => $connection->id, 'kind' => $exception->kind, 'status' => $exception->status]);

        return $message;
    }
}
