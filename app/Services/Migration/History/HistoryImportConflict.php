<?php

namespace App\Services\Migration\History;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The batch is not in a state that allows the action (409): it was
 * already confirmed (`batch_locked`), the summary the admin confirmed
 * changed meanwhile (`summary_changed`) or the batch cannot be resumed
 * (`not_resumable`). JSON requests get {code, message}; Inertia requests
 * go back with the message as an error.
 */
class HistoryImportConflict extends RuntimeException
{
    public const BATCH_LOCKED = 'batch_locked';

    public const SUMMARY_CHANGED = 'summary_changed';

    public const NOT_RESUMABLE = 'not_resumable';

    public const BUSY = 'batch_busy';

    public function __construct(public readonly string $conflict, string $message)
    {
        parent::__construct($message);
    }

    public static function locked(): self
    {
        return new self(self::BATCH_LOCKED, 'Esta importación ya se confirmó o se canceló; ya no se puede cambiar.');
    }

    public static function summaryChanged(): self
    {
        return new self(self::SUMMARY_CHANGED, 'El resumen cambió desde que lo revisaste. Revísalo de nuevo antes de confirmar.');
    }

    public static function busy(): self
    {
        return new self(self::BUSY, 'Todavía se están leyendo archivos o actualizando el inventario. Espera unos segundos y vuelve a revisar el resumen.');
    }

    public static function notResumable(): self
    {
        return new self(self::NOT_RESUMABLE, 'Esta importación no tiene trabajo pendiente para continuar.');
    }

    /**
     * Replaces the default reporting (which logs at error level): a 409 is
     * an expected answer, not an application failure.
     */
    public function report(Request $request): void
    {
        Log::info('Conflicto en un lote de importación de historial.', [
            'conflict' => $this->conflict,
            'user_id' => $request->user()?->getAuthIdentifier(),
            'route' => $request->route()?->getName(),
        ]);
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['code' => $this->conflict, 'message' => $this->getMessage()], 409);
        }

        return back()->withErrors(['batch' => $this->getMessage()])->with('history_import_conflict', $this->conflict);
    }
}
