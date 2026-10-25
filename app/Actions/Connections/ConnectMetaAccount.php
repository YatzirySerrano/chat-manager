<?php

namespace App\Actions\Connections;

use App\Enums\AuditAction;
use App\Enums\ConnectionChannel;
use App\Events\Connections\ConnectionStatusChanged;
use App\Models\Connection;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Meta\MetaApp;
use App\Services\Meta\MetaGraphException;
use App\Services\Meta\MetaPlatformClient;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates (or re-authorizes) the Messenger / Instagram connection of the
 * account the administrator chose after authorizing with Meta:
 *
 * 1. the account is unique per channel (external_account_id): an existing
 *    connection of the same account receives the new token (re-authorization,
 *    its configuration and history stay); an account of a deleted connection
 *    must be restored first, like a WhatsApp number;
 * 2. the page / Instagram token is stored encrypted with the granted
 *    permissions, its expiry (Instagram), who authorized and when; a new
 *    connection starts inactive, like every connection;
 * 3. the account is subscribed to this app's webhook fields and the
 *    subscription is read back (subscribed_apps); a failure is reported,
 *    never hidden, and can be retried by authorizing again;
 * 4. the authorization is audited (connection.meta_authorized) without tokens.
 */
class ConnectMetaAccount
{
    public function __construct(private MetaPlatformClient $client, private AuditLogger $audit) {}

    /**
     * @param  array{id: string, name: string, username?: string|null, picture: string|null, access_token: string, scopes: list<string>, expires_at: int|null, meta_user_id?: string|null}  $account
     *
     * @throws ValidationException
     */
    public function handle(ConnectionChannel $channel, array $account, User $actor): Connection
    {
        $store = fn (): Connection => DB::transaction(function () use ($channel, $account, $actor): Connection {
            $existing = Connection::query()
                ->where('channel', $channel)
                ->where('external_account_id', $account['id'])
                ->lockForUpdate()
                ->first();

            if ($existing?->isDeleted()) {
                throw ValidationException::withMessages([
                    'account_id' => "Esta cuenta pertenece a una conexión eliminada («{$existing->name}»). Restáurala desde la lista de conexiones y vuelve a autorizarla.",
                ]);
            }

            $connection = $existing ?? new Connection([
                'name' => Str::limit($account['name'], 120, ''),
                'channel' => $channel,
                'provider' => $channel->defaultProvider(),
            ]);

            $connection->forceFill([
                'external_account_id' => $account['id'],
                'external_account_name' => Str::limit($account['name'], 255, ''),
                'external_account_picture' => $account['picture'],
                'access_token' => $account['access_token'],
                'granted_scopes' => $account['scopes'],
                'token_expires_at' => $account['expires_at'] === null ? null : CarbonImmutable::createFromTimestamp($account['expires_at']),
                'authorized_at' => now(),
                'authorized_by_user_id' => $actor->id,
                // Meta just answered with this token: it is verified.
                'credentials_verified_at' => now(),
                'credentials_error' => null,
                'created_by_user_id' => $connection->exists ? $connection->created_by_user_id : $actor->id,
            ]);

            // A new token starts a new refresh cycle (Instagram).
            $signals = $connection->provider_signals ?? [];
            unset($signals['token_refresh']);

            if (filled($account['meta_user_id'] ?? null)) {
                $signals['meta_authorization'] = ['at' => now()->toIso8601String(), 'value' => ['user_id' => (string) $account['meta_user_id']]];
            }

            $connection->provider_signals = $signals;
            $connection->save();

            return $connection;
        });

        // Two simultaneous first authorizations of the same account: the
        // loser of the unique (channel, external_account_id) insert retries
        // and updates the connection the other one created.
        try {
            $connection = $store();
        } catch (UniqueConstraintViolationException) {
            $connection = $store();
        }

        $subscribed = $this->subscribe($connection);

        $this->audit->record(AuditAction::ConnectionMetaAuthorized, $connection, [
            'channel' => $channel->value,
            'external_account_id' => $connection->external_account_id,
            'account_name' => $connection->external_account_name,
            'granted_scopes' => $connection->granted_scopes,
            'expires_at' => $connection->token_expires_at?->toIso8601String(),
            'reauthorized' => ! $connection->wasRecentlyCreated,
            'subscribed' => $subscribed,
        ], $actor);

        ConnectionStatusChanged::dispatch($connection->id, 'credentials');

        return $connection;
    }

    /**
     * Subscribe the account to the webhook fields and read the subscription
     * back; the result is stored on the connection (app_subscribed).
     */
    public function subscribe(Connection $connection): bool
    {
        $fields = MetaApp::SUBSCRIBED_FIELDS[$connection->channel->value] ?? [];
        $token = (string) $connection->access_token;
        $accountId = (string) $connection->external_account_id;

        try {
            $this->client->subscribe($connection->channel, $accountId, $token, $fields);
            $current = $this->client->subscription($connection->channel, $accountId, $token);
            $subscribed = $current['subscribed'] && array_diff(['messages'], $current['fields']) === [];
            $error = $subscribed ? null : 'Meta no confirmó la suscripción del webhook. Vuelve a autorizar la cuenta para intentarlo de nuevo.';
        } catch (MetaGraphException $exception) {
            Log::warning('Meta account subscription failed.', ['connection_id' => $connection->id, 'kind' => $exception->kind, 'meta_code' => $exception->metaCode]);
            $subscribed = false;
            $error = 'No fue posible suscribir la cuenta al webhook: '.$exception->getMessage();
        }

        $connection->forceFill([
            'app_subscribed' => $subscribed,
            'subscription_checked_at' => now(),
            ...($error === null ? [] : ['last_error_at' => now(), 'last_error_message' => Str::limit($error, 500, '')]),
        ])->save();

        return $subscribed;
    }
}
