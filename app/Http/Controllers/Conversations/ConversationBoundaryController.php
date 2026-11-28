<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Conversations\AdjustRequestBoundary;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Conversations\ConversationLifecycle;
use App\Services\Conversations\RequestBoundary;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «Corregir tamaño de la solicitud», from the separator of a request in
 * «Conversación completa»: the boundary between this conversation and the
 * request right before it (same contact and connection).
 *
 * - show: what the dialog lists (RequestBoundary::window()): both requests
 *   with their versions and the messages around the boundary;
 * - store: moves the boundary to a message (`starts_at`), or joins this
 *   request into the previous one (`starts_at` empty), through
 *   AdjustRequestBoundary, which checks everything again under lock.
 *
 * The conversation is authorized like its chat (404 when the viewer may
 * not see it); not being allowed to adjust it is a 403. JSON only.
 */
class ConversationBoundaryController extends ConversationOperationController
{
    public function show(Request $request, Conversation $conversation, RequestBoundary $boundary): JsonResponse
    {
        $this->ensureVisible($conversation);

        /** @var User $viewer */
        $viewer = $request->user();
        $previous = $boundary->previousOf($conversation);

        if ($previous === null || ! $boundary->canAdjust($viewer, $conversation)) {
            throw new AuthorizationException(ConversationLifecycle::FORBIDDEN_MESSAGE);
        }

        return response()->json($boundary->window($previous, $conversation, $viewer));
    }

    public function store(Request $request, Conversation $conversation, AdjustRequestBoundary $adjust): JsonResponse
    {
        $this->ensureVisible($conversation);

        $validated = $request->validate([
            'previous_id' => ['required', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'integer', 'min:1'],
            'version' => ['required', 'integer', 'min:0'],
            'previous_version' => ['required', 'integer', 'min:0'],
        ], attributes: [
            'previous_id' => 'solicitud anterior',
            'starts_at' => 'mensaje donde empieza la solicitud',
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $this->ensurePermitted($actor, 'adjustBoundary', $conversation);

        $result = $adjust->handle(
            $conversation,
            (int) $validated['previous_id'],
            isset($validated['starts_at']) ? (int) $validated['starts_at'] : null,
            $actor,
            (int) $validated['version'],
            (int) $validated['previous_version'],
        );

        return response()->json([
            ...$result,
            'message' => match ($result['merged']) {
                AdjustRequestBoundary::MERGED_INTO_PREVIOUS => 'Listo: las dos solicitudes quedaron unidas en la anterior.',
                AdjustRequestBoundary::MERGED_INTO_CURRENT => 'Listo: las dos solicitudes quedaron unidas en la siguiente.',
                default => 'Listo: se corrigió el tamaño de la solicitud.',
            },
        ]);
    }
}
