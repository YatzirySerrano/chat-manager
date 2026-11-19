<?php

namespace App\Http\Controllers\TeamChat;

use App\Actions\TeamChat\AcceptDeskRequest;
use App\Actions\TeamChat\CompleteDeskRequest;
use App\Http\Controllers\Controller;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Services\TeamChat\ControlDesk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * «Mesa de control» actions of an auditor: accept a pending request
 * («Aceptar solicitud») and close the one she attends («Solicitud
 * completa»). Conflicts (taken by someone else, not waiting, limit
 * reached) are TeamDeskConflict: back to the page with the explanation.
 */
class TeamDeskController extends Controller
{
    /**
     * «Escribir a la mesa de control»: the person's own desk thread (created
     * the first time). An administrator or auditor who writes there is
     * attended by the rest of the desk, like any requester.
     */
    public function open(Request $request, ControlDesk $desk): RedirectResponse
    {
        Gate::authorize('viewAny', TeamThread::class);

        /** @var User $actor */
        $actor = $request->user();

        return to_route('team-chat.threads.show', ['thread' => $desk->threadFor($actor)]);
    }

    public function accept(Request $request, TeamThread $thread, AcceptDeskRequest $acceptDeskRequest): RedirectResponse
    {
        Gate::authorize('attendDesk', $thread);

        /** @var User $actor */
        $actor = $request->user();
        $acceptDeskRequest->handle($thread, $actor);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Aceptaste la solicitud. Ahora está en «Mías».']);

        // Opened, within «Mías», where it now lives.
        return to_route('team-chat.threads.show', ['thread' => $thread, 'desk' => 'mine']);
    }

    public function complete(Request $request, TeamThread $thread, CompleteDeskRequest $completeDeskRequest): RedirectResponse
    {
        Gate::authorize('attendDesk', $thread);

        /** @var User $actor */
        $actor = $request->user();
        $completeDeskRequest->handle($thread, $actor);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Solicitud completada. Si la persona vuelve a escribir, regresará a «Pendientes».']);

        return back();
    }
}
