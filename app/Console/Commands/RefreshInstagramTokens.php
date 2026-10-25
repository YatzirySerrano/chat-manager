<?php

namespace App\Console\Commands;

use App\Enums\ConnectionChannel;
use App\Models\Connection;
use App\Services\Meta\ConnectionMonitor;
use App\Services\Meta\MetaGraphException;
use App\Services\Meta\MetaPlatformClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Renews the long-lived tokens of Instagram connections (Instagram Login
 * tokens last 60 days and can be refreshed once they are at least 24 hours
 * old and not expired; scheduled daily in routes/console.php).
 *
 * Tokens that expire within REFRESH_WITHIN_DAYS (or whose expiry is
 * unknown) are refreshed; the new token is saved under the connection lock
 * only if the connection still holds the token that was refreshed. An expired
 * or refused token marks the connection's credentials as invalid so an
 * administrator authorizes it again. Facebook page tokens do not expire
 * and are not touched.
 */
#[Signature('meta:refresh-instagram-tokens')]
#[Description('Refresh the long-lived tokens of Instagram connections that expire soon')]
class RefreshInstagramTokens extends Command
{
    public const REFRESH_WITHIN_DAYS = 10;

    public const MINIMUM_AGE_HOURS = 24;

    /** Lifetime assumed for a long-lived Instagram token whose expiry Meta did not report. */
    public const DEFAULT_LIFETIME_DAYS = 60;

    public function handle(MetaPlatformClient $client, ConnectionMonitor $monitor): int
    {
        $refreshed = 0;
        $failed = 0;

        $connections = Connection::query()
            ->where('channel', ConnectionChannel::Instagram)
            ->notDeleted()
            ->whereNotNull('access_token')
            // An unknown expiry (Meta sent no expires_in) is refreshed too.
            ->where(fn ($query) => $query->whereNull('token_expires_at')->orWhere('token_expires_at', '<=', now()->addDays(self::REFRESH_WITHIN_DAYS)))
            ->get();

        foreach ($connections as $connection) {
            if ($connection->token_expires_at?->isPast()) {
                $this->expire($connection, 'El token de Instagram venció. Vuelve a autorizar la cuenta desde Conexiones.');
                $failed++;

                continue;
            }

            $issuedAt = $connection->provider_signals['token_refresh']['at'] ?? $connection->authorized_at?->toIso8601String();

            if (is_string($issuedAt) && now()->subHours(self::MINIMUM_AGE_HOURS)->lessThan($issuedAt)) {
                continue;
            }

            try {
                $token = $client->refreshInstagramToken((string) $connection->access_token);
            } catch (MetaGraphException $exception) {
                if ($exception->kind === MetaGraphException::TOKEN_INVALID) {
                    $this->expire($connection, $exception->getMessage());
                } else {
                    $monitor->failure($connection, 'No fue posible renovar el token de Instagram: '.$exception->getMessage());
                }

                $failed++;

                continue;
            }

            $refreshed += $this->store($connection, (string) $connection->access_token, $token) ? 1 : 0;
        }

        $this->info("Tokens de Instagram renovados: {$refreshed}; con problema: {$failed}.");

        return self::SUCCESS;
    }

    /**
     * Save the renewed token under the connection lock, unless the row
     * changed during the call (disconnected, deleted or re-authorized with
     * another token): that newer state always wins.
     *
     * @param  array{access_token: string, expires_in: int|null}  $token
     */
    private function store(Connection $connection, string $previousToken, array $token): bool
    {
        return DB::transaction(function () use ($connection, $previousToken, $token): bool {
            $current = Connection::query()->whereKey($connection->id)->lockForUpdate()->first();

            if ($current === null || $current->isDeleted() || blank($current->access_token) || ! hash_equals($previousToken, (string) $current->access_token)) {
                return false;
            }

            $signals = $current->provider_signals ?? [];
            $signals['token_refresh'] = ['at' => now()->toIso8601String(), 'value' => ['expires_in' => $token['expires_in']]];

            $current->forceFill([
                'access_token' => $token['access_token'],
                // Instagram Login long-lived tokens last 60 days when Meta omits expires_in.
                'token_expires_at' => now()->addSeconds($token['expires_in'] ?? self::DEFAULT_LIFETIME_DAYS * 86400),
                'provider_signals' => $signals,
            ])->save();

            return true;
        });
    }

    private function expire(Connection $connection, string $message): void
    {
        $connection->forceFill(['credentials_error' => $message])->save();
    }
}
