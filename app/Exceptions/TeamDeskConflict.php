<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use RuntimeException;

/**
 * An expected conflict of the «Mesa de control»: another auditor accepted
 * the request first, it is no longer waiting, or the auditor reached her
 * simultaneous-attention limit. Answered as 409 for JSON clients or back to
 * the page with the explanation (an error toast and `errors.desk`), never
 * as a server error, and logged at info level for auditing races.
 */
class TeamDeskConflict extends RuntimeException
{
    public const CODE = 'desk_conflict';

    public static function alreadyTaken(): self
    {
        return new self('Otra persona de la mesa de control ya aceptó esta solicitud.');
    }

    public static function notWaiting(): self
    {
        return new self('Esta solicitud ya no está en espera.');
    }

    public static function notYours(): self
    {
        return new self('Solo quien atiende la solicitud puede marcarla como completa.');
    }

    public static function capacity(string $message): self
    {
        return new self($message);
    }

    public function report(Request $request): void
    {
        $thread = $request->route('thread');

        Log::info('Conflicto en la mesa de control del chat interno.', [
            'reason' => $this->getMessage(),
            'team_thread_id' => is_object($thread) && method_exists($thread, 'getKey') ? $thread->getKey() : null,
            'user_id' => $request->user()?->getAuthIdentifier(),
            'route' => $request->route()?->getName(),
        ]);
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['code' => self::CODE, 'message' => $this->getMessage()], 409);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => $this->getMessage()]);

        return back()->withErrors(['desk' => $this->getMessage()]);
    }
}
