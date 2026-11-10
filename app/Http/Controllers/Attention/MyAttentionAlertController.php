<?php

namespace App\Http\Controllers\Attention;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Attention\AttentionInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in person's own attention alerts: the ones still waiting for
 * «Entendido» (asked for on load, after a real-time signal, on reconnection
 * and periodically without real-time), the moment their application showed
 * them, and the acknowledgement. Bound to the person's own rows.
 */
class MyAttentionAlertController extends Controller
{
    public function index(Request $request, AttentionInbox $inbox): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $inbox->pending($user),
            'total' => $inbox->pendingCount($user),
        ]);
    }

    public function delivered(Request $request, AttentionInbox $inbox): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'max:'.AttentionInbox::PENDING_LIMIT],
            'ids.*' => ['integer', 'min:1'],
        ]);

        /** @var User $user */
        $user = $request->user();

        return response()->json(['updated' => $inbox->markDelivered($user, array_values(array_map('intval', $validated['ids'])))]);
    }

    public function acknowledge(Request $request, int $recipient, AttentionInbox $inbox): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($inbox->acknowledge($user, $recipient), 404);

        return response()->json(['acknowledged' => true]);
    }
}
