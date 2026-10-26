<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\HistoryImportColumnMappingRequest;
use App\Http\Requests\Admin\HistoryImportFileRequest;
use App\Models\DataTransfer;
use App\Models\HistoryImportFile;
use App\Models\User;
use App\Policies\HistoryImportPolicy;
use App\Services\Migration\History\HistoryFileIngestor;
use App\Services\Migration\History\HistoryFileStore;
use App\Services\Migration\History\HistoryImportBatches;
use App\Services\Migration\History\HistoryInventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Files of a history import batch: upload (read in the background) and
 * the column mapping override of one file (read again).
 */
class HistoryImportFileController extends Controller
{
    public function store(HistoryImportFileRequest $request, DataTransfer $transfer, HistoryFileIngestor $ingestor, HistoryImportBatches $batches): RedirectResponse|JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $file = $ingestor->add($transfer, $request->file('file'), $request->kind(), $actor);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['file_id' => $file->id, 'batch' => $batches->present($transfer->refresh())], 201);
        }

        Inertia::flash('toast', ['type' => 'info', 'message' => 'Archivo recibido. Se está leyendo; nada se importa todavía.']);

        return to_route('admin.history-import.show', $transfer);
    }

    /**
     * Remove a file from a draft (its staged rows and stored copy go; the
     * inventory is rebuilt).
     */
    public function destroy(Request $request, DataTransfer $transfer, HistoryImportFile $file, HistoryImportBatches $batches, HistoryFileStore $store, HistoryInventory $inventory): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        app(HistoryImportPolicy::class)->classify($actor, $transfer)->authorize();
        abort_unless($file->data_transfer_id === $transfer->id, 404);

        $batches->removeFile($transfer, $file, $actor, $store, $inventory);

        return response()->json(['batch' => $batches->present($transfer->refresh())]);
    }

    /**
     * Read a file again after an interrupted or failed reading.
     */
    public function retry(Request $request, DataTransfer $transfer, HistoryImportFile $file, HistoryFileIngestor $ingestor, HistoryImportBatches $batches): JsonResponse
    {
        app(HistoryImportPolicy::class)->classify($request->user(), $transfer)->authorize();
        abort_unless($file->data_transfer_id === $transfer->id, 404);

        $ingestor->retry($file);

        return response()->json(['batch' => $batches->present($transfer->refresh())], 202);
    }

    public function mapping(HistoryImportColumnMappingRequest $request, DataTransfer $transfer, HistoryImportFile $file, HistoryFileIngestor $ingestor, HistoryImportBatches $batches): JsonResponse
    {
        abort_unless($file->data_transfer_id === $transfer->id, 404);

        $ingestor->remap($file, (array) $request->input('mapping', []));

        return response()->json(['batch' => $batches->present($transfer->refresh())]);
    }
}
