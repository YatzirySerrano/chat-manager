<?php

namespace App\Actions\Connections;

use App\Enums\AuditAction;
use App\Models\Connection;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Activates or deactivates a connection. Activation requires the identifiers
 * and the access token to be saved; verified credentials are recommended but
 * not required, so the outcome reports whether they were unverified.
 */
class ChangeConnectionStatus
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(Connection $connection, bool $active, User $actor): Connection
    {
        return DB::transaction(function () use ($connection, $active, $actor): Connection {
            $connection = Connection::query()->whereKey($connection->id)->lockForUpdate()->firstOrFail();

            if ($connection->is_active === $active) {
                return $connection;
            }

            if ($active && ! $connection->hasRequiredCredentials()) {
                throw ValidationException::withMessages([
                    'is_active' => 'Para activar la conexión guarda primero el Phone number ID, el WABA ID y el token de acceso.',
                ]);
            }

            $connection->forceFill(['is_active' => $active])->save();

            $this->audit->record(
                $active ? AuditAction::ConnectionActivated : AuditAction::ConnectionDeactivated,
                $connection,
                $active ? ['health' => $connection->configurationHealth()->value] : [],
                $actor,
            );

            return $connection;
        });
    }
}
