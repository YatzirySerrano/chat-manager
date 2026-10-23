<?php

namespace App\Actions\Connections;

use App\Enums\AuditAction;
use App\Events\Connections\ConnectionStatusChanged;
use App\Models\Connection;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Restores a logically deleted connection. It comes back INACTIVE and with
 * its credentials, webhook verification and app subscription pending:
 * nothing proves the link with Meta still works, so it must be verified and
 * activated again explicitly. Traffic history (last inbound/outbound) is
 * kept for reports.
 */
class RestoreConnection
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Connection $connection, User $actor): Connection
    {
        return DB::transaction(function () use ($connection, $actor): Connection {
            /** @var Connection $locked */
            $locked = Connection::query()->lockForUpdate()->findOrFail($connection->id);

            if (! $locked->isDeleted()) {
                return $locked;
            }

            $locked->forceFill([
                'deleted_at' => null,
                'is_active' => false,
                'credentials_verified_at' => null,
                // Integration evidence must be obtained again; traffic history is kept.
                'webhook_verified_at' => null,
                'app_subscribed' => null,
                'subscription_checked_at' => null,
            ])->save();
            ConnectionStatusChanged::dispatch($locked->id, 'restored');

            $this->audit->record(AuditAction::ConnectionRestored, $locked, [
                'verification_reset' => true,
            ], $actor);

            return $locked;
        });
    }
}
