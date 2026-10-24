<?php

namespace App\Policies;

use App\Enums\ConnectionChannel;
use App\Enums\Permission;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Connections hold company-wide credentials, so both permissions require the
 * organization-wide scope (enforced by User::holdsPermission). Managing
 * implies seeing, so a role with only "manage" is never locked out of the
 * screens it administers.
 */
class ConnectionPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->holdsPermission(Permission::ViewConnections)
            || $actor->holdsPermission(Permission::ManageConnections);
    }

    /**
     * A deleted connection answers as nonexistent to people who cannot
     * consult deleted records.
     */
    public function view(User $actor, Connection $connection): Response
    {
        if (! $this->viewAny($actor)) {
            return Response::deny();
        }

        return $connection->isDeleted() && ! $actor->canViewDeletedRecords() ? Response::denyAsNotFound() : Response::allow();
    }

    public function create(User $actor): bool
    {
        return $actor->holdsPermission(Permission::ManageConnections);
    }

    /**
     * A deleted connection is read-only until it is restored.
     */
    public function update(User $actor, Connection $connection): bool
    {
        return ! $connection->isDeleted() && $actor->holdsPermission(Permission::ManageConnections);
    }

    public function changeStatus(User $actor, Connection $connection): bool
    {
        return $this->update($actor, $connection);
    }

    /**
     * Verifying credentials and managing the app subscription only exist for
     * WhatsApp: a web chat connection has nothing to verify with Meta.
     */
    public function verify(User $actor, Connection $connection): bool
    {
        return $connection->channel === ConnectionChannel::Whatsapp && $this->update($actor, $connection);
    }

    /**
     * The widget settings (and the rotation of its key and identity secret)
     * of a web chat connection are managed with the connections permission.
     */
    public function manageWidget(User $actor, Connection $connection): bool
    {
        return $connection->isWebChat() && $this->update($actor, $connection);
    }

    public function delete(User $actor, Connection $connection): bool
    {
        return $this->update($actor, $connection);
    }

    public function restore(User $actor, Connection $connection): bool
    {
        return $connection->isDeleted()
            && $actor->holdsPermission(Permission::ManageConnections)
            && $actor->canViewDeletedRecords();
    }
}
