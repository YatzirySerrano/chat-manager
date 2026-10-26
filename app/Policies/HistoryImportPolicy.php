<?php

namespace App\Policies;

use App\Enums\DataTransferType;
use App\Enums\Permission;
use App\Models\Conversation;
use App\Models\DataTransfer;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * The Whaticket history importer and the archived history. Every check is
 * on permissions, never on role names:
 *
 * - history-import.view: list batches, their files, inventory and report;
 * - history-import.classify: create batches, upload files, map columns and
 *   records, decide destinations and rules;
 * - history-import.execute: confirm, resume;
 * - history-import.archive-view: consult imported history (read-only).
 *
 * The first three are reserved to the root authority and all four need the
 * global scope (Permission). A batch is organizational, not personal: every
 * holder of history-import.view sees every batch; anybody else is told it
 * does not exist (404), so identifiers reveal nothing. Direct calls are
 * used (not Gate::policy), because DataTransfer already has its own policy.
 */
class HistoryImportPolicy
{
    public function viewAny(User $actor): Response
    {
        return $actor->holdsPermission(Permission::ViewHistoryImports)
            ? Response::allow()
            : Response::deny('No tienes autorización para ver las importaciones de historial.');
    }

    public function create(User $actor): Response
    {
        return $actor->holdsPermission(Permission::ViewHistoryImports) && $actor->holdsPermission(Permission::ClassifyHistoryImports)
            ? Response::allow()
            : Response::deny('No tienes autorización para importar historial.');
    }

    public function view(User $actor, DataTransfer $batch): Response
    {
        if ($batch->type !== DataTransferType::HistoryImport || ! $actor->holdsPermission(Permission::ViewHistoryImports)) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }

    public function classify(User $actor, DataTransfer $batch): Response
    {
        $view = $this->view($actor, $batch);

        if ($view->denied()) {
            return $view;
        }

        return $actor->holdsPermission(Permission::ClassifyHistoryImports)
            ? Response::allow()
            : Response::deny('No tienes autorización para clasificar esta importación.');
    }

    public function execute(User $actor, DataTransfer $batch): Response
    {
        $view = $this->view($actor, $batch);

        if ($view->denied()) {
            return $view;
        }

        return $actor->holdsPermission(Permission::ExecuteHistoryImports)
            ? Response::allow()
            : Response::deny('No tienes autorización para ejecutar esta importación.');
    }

    public function viewArchive(User $actor): Response
    {
        return $actor->holdsPermission(Permission::ViewArchivedHistory)
            ? Response::allow()
            : Response::deny('No tienes autorización para consultar el historial de Whaticket.');
    }

    /**
     * One imported conversation: only imported ones, only with the
     * permission (anything else does not exist for this screen).
     */
    public function viewArchived(User $actor, Conversation $conversation): Response
    {
        return $conversation->isImported() && $actor->holdsPermission(Permission::ViewArchivedHistory)
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
