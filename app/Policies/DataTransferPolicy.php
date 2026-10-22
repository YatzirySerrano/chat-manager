<?php

namespace App\Policies;

use App\Enums\DataTransferType;
use App\Enums\Permission;
use App\Models\DataTransfer;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Transfers are private to the user who requested them: anybody else is
 * told the transfer does not exist, so identifiers reveal nothing.
 */
class DataTransferPolicy
{
    /**
     * The "Exportaciones" page: anybody who may export something (module
     * reports with reports.export, the audit log with audit.export).
     */
    public function viewAny(User $actor): bool
    {
        return $this->canExport($actor);
    }

    public function download(User $actor, DataTransfer $transfer): Response
    {
        // Import results are downloaded from the importer (see manageImport).
        if ($transfer->user_id !== $actor->id || $transfer->type !== DataTransferType::Export) {
            return Response::denyAsNotFound();
        }

        if (! $this->canExport($actor)) {
            return Response::deny('No tienes permiso para descargar exportaciones.');
        }

        return $transfer->isDownloadable()
            ? Response::allow()
            : Response::denyWithStatus(410, 'Este archivo ya no está disponible. Genera la exportación de nuevo.');
    }

    /**
     * The contacts importer (upload, template, mapping): reserved to the
     * root authority through contacts.import.
     */
    public function createImport(User $actor): bool
    {
        return $actor->holdsPermission(Permission::ImportContacts);
    }

    /**
     * Preview, confirm, cancel and download the result of an import: only
     * its owner, while still holding contacts.import.
     */
    public function manageImport(User $actor, DataTransfer $transfer): Response
    {
        if ($transfer->user_id !== $actor->id || $transfer->type !== DataTransferType::Import) {
            return Response::denyAsNotFound();
        }

        return $actor->holdsPermission(Permission::ImportContacts)
            ? Response::allow()
            : Response::deny('No tienes permiso para importar contactos.');
    }

    private function canExport(User $actor): bool
    {
        return $actor->holdsPermission(Permission::ExportReports) || $actor->holdsPermission(Permission::ExportAuditLog);
    }
}
