<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Operations\FailedJobActionRefused;
use App\Services\Operations\FailedJobs;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Administrative actions on failed jobs (system.manage): retry only the
 * idempotent job classes of the allowlist, discard any. Both are audited.
 */
class SystemFailedJobController extends Controller
{
    public function retry(Request $request, string $uuid, FailedJobs $failedJobs): RedirectResponse
    {
        Gate::authorize('manage-system');

        /** @var User $actor */
        $actor = $request->user();

        try {
            $failedJobs->retry($uuid, $actor);
            Inertia::flash('toast', ['type' => 'success', 'message' => 'El trabajo volvió a la cola; un worker lo procesará en breve.']);
        } catch (FailedJobActionRefused $refused) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $refused->getMessage()]);
        } catch (LockTimeoutException) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => 'Otra persona está reintentando este trabajo. Actualiza la página en unos segundos.']);
        }

        return back();
    }

    public function destroy(Request $request, string $uuid, FailedJobs $failedJobs): RedirectResponse
    {
        Gate::authorize('manage-system');

        /** @var User $actor */
        $actor = $request->user();

        try {
            $failedJobs->forget($uuid, $actor);
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Trabajo fallido descartado.']);
        } catch (FailedJobActionRefused $refused) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $refused->getMessage()]);
        }

        return back();
    }
}
