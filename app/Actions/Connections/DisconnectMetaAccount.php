<?php

namespace App\Actions\Connections;

use App\Enums\AuditAction;
use App\Events\Connections\ConnectionStatusChanged;
use App\Models\Connection;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Meta\MetaGraphException;
use App\Services\Meta\MetaPlatformClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Disconnects a Messenger / Instagram connection from Meta:
 *
 * 1. cancels the webhook subscription of the page / Instagram account
 *    (DELETE subscribed_apps), best effort: Meta being unreachable or the
 *    token already revoked never blocks the disconnection;
 * 2. forgets the token, its permissions and expiry, deactivates the
 *    connection and marks the subscription as cancelled. The user token is
 *    never kept, so there is nothing else to revoke here; the person can
 *    also remove the app from their Facebook / Instagram settings;
 * 3. keeps the connection, its account id, conversations and history, so
 *    the same account can be authorized again later;
 * 4. audits connection.meta_disconnected (by an administrator, or by Meta's
 *    deauthorization callback when $actor is null).
 */
class DisconnectMetaAccount
{
    public function __construct(private MetaPlatformClient $client, private AuditLogger $audit) {}

    public function handle(Connection $connection, ?User $actor, string $reason = 'administrator', bool $callMeta = true): Connection
    {
        $unsubscribed = false;
        $token = (string) $connection->access_token;

        if ($callMeta && $token !== '' && filled($connection->external_account_id)) {
            try {
                $this->client->unsubscribe($connection->channel, (string) $connection->external_account_id, $token);
                $unsubscribed = true;
            } catch (MetaGraphException $exception) {
                Log::warning('Meta subscription could not be cancelled while disconnecting.', ['connection_id' => $connection->id, 'kind' => $exception->kind, 'meta_code' => $exception->metaCode]);
            }
        }

        $connection = DB::transaction(function () use ($connection, $reason): Connection {
            $locked = Connection::query()->lockForUpdate()->findOrFail($connection->id);

            $locked->forceFill([
                'access_token' => null,
                'granted_scopes' => null,
                'token_expires_at' => null,
                'is_active' => false,
                'app_subscribed' => false,
                'subscription_checked_at' => now(),
                'credentials_verified_at' => null,
                'credentials_error' => $reason === 'deauthorized'
                    ? 'La persona que autorizó la cuenta retiró el acceso de la aplicación en Meta. Vuelve a autorizarla para reanudar la conexión.'
                    : null,
            ])->save();

            return $locked;
        });

        ConnectionStatusChanged::dispatch($connection->id, 'deactivated');

        $this->audit->record(AuditAction::ConnectionMetaDisconnected, $connection, [
            'channel' => $connection->channel->value,
            'external_account_id' => $connection->external_account_id,
            'reason' => $reason,
            'unsubscribed' => $unsubscribed,
        ], $actor);

        return $connection;
    }
}
