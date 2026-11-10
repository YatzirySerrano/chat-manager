<?php

namespace App\Http\Controllers\Attention;

use App\Actions\Attention\SendAttentionAlert;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attention\StoreAttentionAlertRequest;
use App\Models\AttentionAlert;
use App\Models\User;
use App\Services\Attention\AttentionOverview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Sending attention alerts and consulting who received and acknowledged
 * each one (gate send-attention-alerts, checked on every request).
 */
class AttentionAlertController extends Controller
{
    public function store(StoreAttentionAlertRequest $request, SendAttentionAlert $send): RedirectResponse
    {
        /** @var User $sender */
        $sender = $request->user();
        $alert = $send->handle($sender, $request->alert());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $alert->recipients_count === 1
                ? 'Alerta enviada a 1 agente.'
                : "Alerta enviada a {$alert->recipients_count} agentes.",
        ]);

        return back();
    }

    /**
     * The recipients of one alert with their delivery and acknowledgement.
     * An alert outside the viewer's history does not exist for them.
     */
    public function show(Request $request, AttentionAlert $alert, AttentionOverview $overview): JsonResponse
    {
        Gate::authorize('send-attention-alerts');

        /** @var User $viewer */
        $viewer = $request->user();

        abort_unless($overview->canSeeAlert($viewer, $alert), 404);

        $alert->loadCount($overview->recipientCounts($viewer))->load('sender:id,name');

        return response()->json([
            'alert' => $overview->presentAlert($alert, $viewer),
            'recipients' => $overview->recipients($alert, $viewer),
        ]);
    }
}
