<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Storage\StorageStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración > Almacenamiento: where the files of the conversations
 * are kept (NAS share, S3-compatible bucket or the server's disk), whether
 * that storage answers and what it has been saving. Same audience as
 * Sistema (system.view, organization-wide scope); the snapshot is
 * sanitized by StorageStatus.
 */
class StorageStatusController extends Controller
{
    public function index(StorageStatus $status): Response
    {
        Gate::authorize('view-system');

        return Inertia::render('admin/storage/Index', [
            'status' => $status->snapshot(),
            'canManage' => Gate::allows('manage-system'),
        ]);
    }

    /**
     * The same snapshot as JSON for the live polling of the page.
     */
    public function status(StorageStatus $status): JsonResponse
    {
        Gate::authorize('view-system');

        return response()->json($status->snapshot())->header('Cache-Control', 'no-store, private');
    }

    /**
     * Probes the storage now instead of waiting for the scheduled check.
     */
    public function probe(StorageStatus $status): JsonResponse
    {
        Gate::authorize('manage-system');

        $status->probeNow();

        return response()->json($status->snapshot())->header('Cache-Control', 'no-store, private');
    }
}
