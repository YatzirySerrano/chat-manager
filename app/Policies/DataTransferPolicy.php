<?php

namespace App\Policies;

use App\Enums\DataTransferType;
use App\Enums\Permission;
use App\Models\DataTransfer;
use App\Models\User;
use App\Reports\ReportRegistry;
use App\Services\Exports\ReportExporter;
use Illuminate\Auth\Access\Response;

/**
 * Transfers are private to the user who requested them: anybody else is
 * told the transfer does not exist, so identifiers reveal nothing.
 */
class DataTransferPolicy
{
    public function __construct(private ReportRegistry $registry, private ReportExporter $exporter) {}

    /**
     * The "Exportaciones" page: anybody who may export something (module
     * reports with reports.export, the audit log with audit.export).
     */
    public function viewAny(User $actor): bool
    {
        return $this->canExport($actor);
    }

    /**
     * Downloading checks the same as generating, with the permissions the
     * owner holds now: the report's export permission, the module and, for
     * single-record reports, the record. Losing any of them since the file
     * was generated blocks the download.
     */
    public function download(User $actor, DataTransfer $transfer): Response
    {
        // Import results are downloaded from the importer (see manageImport).
        if ($transfer->user_id !== $actor->id || $transfer->type !== DataTransferType::Export) {
            return Response::denyAsNotFound();
        }

        $definition = $this->registry->find((string) $transfer->report);

        if ($definition === null || ! $this->exporter->allowsStored($definition, $actor, $transfer->filters ?? [])) {
            return Response::deny('No tienes permiso para descargar esta exportación.');
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
