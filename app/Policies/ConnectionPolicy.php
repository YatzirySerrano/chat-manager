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

    /**
     * The chatbot's welcome menu of the connection (connections.chatbot,
     * without needing connections.manage). A deleted connection has none.
     */
    public function configureChatbot(User $actor, Connection $connection): bool
    {
        return ! $connection->isDeleted() && $actor->holdsPermission(Permission::ManageChatbot);
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
        return $connection->channel === ConnectionChannel::Whatsapp && ! $connection->isWhatsAppWeb() && $this->update($actor, $connection);
    }

    /**
     * Link, reconnect, disconnect or sign out the phone of a WhatsApp Web
     * (QR) connection: the same people who manage connections.
     */
    public function manageWebSession(User $actor, Connection $connection): bool
    {
        return $connection->isWhatsAppWeb() && $this->update($actor, $connection);
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

    /**
     * «Eliminar definitivamente»: erasing the connection with everything it
     * holds (DeleteConnectionPermanently). Reserved to the root authority
     * (permanent-deletion.connections), whether or not the connection was
     * already deleted logically.
     */
    public function deletePermanently(User $actor, Connection $connection): bool
    {
        return $actor->holdsPermission(Permission::PurgeConnections);
    }

    /**
     * Moving conversations out of this connection into another one of the
     * same channel (MoveConnectionConversations). The source may be deleted
     * (the historical Whaticket connection is created deleted); the
     * destination is checked by the action.
     */
    public function moveConversations(User $actor, Connection $connection): bool
    {
        return $actor->holdsPermission(Permission::ManageConnections)
            && $this->view($actor, $connection)->allowed();
    }

    public function restore(User $actor, Connection $connection): bool
    {
        return $connection->isDeleted()
            && $actor->holdsPermission(Permission::ManageConnections)
            && $actor->canViewDeletedRecords();
    }
}
