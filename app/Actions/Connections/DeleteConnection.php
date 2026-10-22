<?php

namespace App\Actions\Connections;

use App\Enums\AuditAction;
use App\Models\Connection;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Logically deletes a connection: it is deactivated (no traffic with Meta),
 * disappears from the operational lists and keeps its conversations,
 * messages and history. Its Phone number ID stays reserved so the number
 * cannot be registered twice; the connection can be restored instead.
 */
class DeleteConnection
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Connection $connection, User $actor): Connection
    {
        return DB::transaction(function () use ($connection, $actor): Connection {
            /** @var Connection $locked */
            $locked = Connection::query()->lockForUpdate()->findOrFail($connection->id);

            if ($locked->isDeleted()) {
                return $locked;
            }

            $wasActive = $locked->is_active;

            $locked->forceFill(['deleted_at' => now(), 'is_active' => false])->save();

            $this->audit->record(AuditAction::ConnectionDeleted, $locked, [
                'was_active' => $wasActive,
                'conversations_kept' => $locked->conversations()->count(),
            ], $actor);

            return $locked;
        });
    }
}
