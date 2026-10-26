<?php

namespace App\Exceptions;

use App\Http\Controllers\Conversations\ConversationController;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * A normal operational conflict (someone else acted first, the data was
 * stale, a rule forbids the change). It is answered as a controlled
 * response: 409 for JSON clients, or back to the page with the explanation
 * as an error, never as a server error.
 *
 * A state that changed meanwhile (closed, taken by another agent, stale
 * version) is the more specific ConversationStateConflict, which carries a
 * code for the interface.
 *
 * Being expected, it is never reported as an application error: it is
 * logged at info level with the operation's context, for auditing races.
 */
class ConversationConflict extends RuntimeException
{
    /** JSON code of a conflict that is not a change of state. */
    public const CODE = 'conflict';

    public static function alreadyTaken(): self
    {
        return new self('Otra persona ya aceptó esta conversación.');
    }

    public static function transition(string $from, string $to): self
    {
        return new self("No es posible pasar una conversación «{$from}» a «{$to}».");
    }

    public static function anotherActive(): self
    {
        return new self('Este contacto ya tiene otra conversación activa en esta conexión; continúa la atención en ella.');
    }

    /**
     * Replaces the default reporting (which logs at error level).
     */
    public function report(Request $request): void
    {
        $conversation = $request->route('conversation');

        Log::info('Conflicto operativo en una conversación.', [
            'reason' => $this->getMessage(),
            'conversation_id' => $conversation instanceof Conversation ? $conversation->getKey() : null,
            'user_id' => $request->user()?->getAuthIdentifier(),
            'route' => $request->route()?->getName(),
        ]);
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            // A generic conflict (limit reached, another active conversation,
            // concurrent change…): its own explanation, under the "conflict"
            // code (the /api/v1 convention). State conflicts carry their own
            // codes (ConversationStateConflict).
            return response()->json(['code' => self::CODE, 'message' => $this->getMessage()], 409);
        }

        return $this->destination($request)->withErrors(['conversation' => $this->getMessage()]);
    }

    /**
     * Back to the previous page, unless the conflict left the conversation
     * outside the user's reach (a colleague accepted or moved it first):
     * that page would now answer "not found" and hide the explanation, so
     * the user returns to the queue, with the filters they had, instead.
     */
    protected function destination(Request $request): RedirectResponse
    {
        $bound = $request->route('conversation');
        $conversation = $bound instanceof Conversation ? Conversation::query()->find($bound->getKey()) : null;

        if ($bound instanceof Conversation && ($conversation === null || ! ($request->user()?->can('view', $conversation) ?? false))) {
            return redirect()->to(ConversationController::listUrl());
        }

        return back();
    }
}
