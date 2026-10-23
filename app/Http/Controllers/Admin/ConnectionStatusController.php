<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Connections\ChangeConnectionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateStatusRequest;
use App\Models\Connection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class ConnectionStatusController extends Controller
{
    /**
     * Activate or deactivate a connection.
     */
    public function update(UpdateStatusRequest $request, Connection $connection, ChangeConnectionStatus $changeStatus): RedirectResponse
    {
        Gate::authorize('changeStatus', $connection);

        $connection = $changeStatus->handle($connection, $request->boolean('is_active'), $request->user());

        if (! $connection->is_active) {
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Conexión desactivada.']);
        } elseif (filled($connection->credentials_error)) {
            Inertia::flash('toast', [
                'type' => 'warning',
                'message' => 'Conexión activada, pero Meta rechazó sus credenciales en la última verificación.',
            ]);
        } elseif ($connection->credentials_verified_at === null) {
            // Past traffic (e.g. a restored connection) does not prove the current credentials.
            Inertia::flash('toast', [
                'type' => 'warning',
                'message' => 'Conexión activada, pero sus credenciales aún no se han verificado con Meta.',
            ]);
        } else {
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Conexión activada.']);
        }

        return back();
    }
}
