<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Conversations\ChangeAgentAvailability;
use App\Enums\AgentAvailability;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AgentAvailabilityController extends Controller
{
    /**
     * The authenticated person declares their own availability; nobody
     * changes someone else's.
     */
    public function update(Request $request, ChangeAgentAvailability $changeAvailability): RedirectResponse
    {
        Gate::authorize('viewAny', Conversation::class);

        $validated = $request->validate([
            'availability' => ['required', Rule::enum(AgentAvailability::class)],
        ]);

        $availability = AgentAvailability::from($validated['availability']);
        $assigned = $changeAvailability->handle($request->user(), $availability);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => match (true) {
                $availability === AgentAvailability::Unavailable => 'Ahora estás no disponible: no recibirás asignaciones automáticas. Tus conversaciones actuales se mantienen.',
                $assigned > 0 => "Ahora estás disponible. Recibiste {$assigned} conversaciones que esperaban en la cola.",
                default => 'Ahora estás disponible para recibir asignaciones automáticas.',
            },
        ]);

        return back();
    }
}
