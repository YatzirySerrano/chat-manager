<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The private media disk could not store or read a file (external disk not
 * configured or not ready, a write that did not reach it, a network error).
 * Nothing was stored: the operation may be repeated later.
 *
 * The message is the Spanish explanation for the team; the reason is a
 * short code for logs and tests. Neither ever carries credentials, bucket
 * names, endpoints or file contents.
 *
 * Answered as 503 for JSON clients (`{message, code: "storage_unavailable"}`)
 * or back to the page with the explanation on the "attachment" field.
 */
class StorageUnavailable extends RuntimeException
{
    public const CODE = 'storage_unavailable';

    public const DEFAULT_MESSAGE = 'No fue posible guardar el archivo porque el almacenamiento de archivos no está disponible en este momento. Inténtalo de nuevo en unos minutos; si continúa, avisa a un administrador.';

    /**
     * @param  string  $reason  Short technical code: not_configured, adapter_missing, local_not_allowed, public_disk, write_failed, verify_failed, read_failed.
     * @param  string  $disk  Name of the disk (never its credentials).
     */
    public function __construct(
        public readonly string $reason,
        public readonly string $disk = '',
        string $message = self::DEFAULT_MESSAGE,
    ) {
        parent::__construct($message);
    }

    /**
     * Logged as a warning with the reason and the disk name only.
     */
    public function report(): void
    {
        Log::warning('Media storage unavailable.', ['reason' => $this->reason, 'disk' => $this->disk]);
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $this->getMessage(), 'code' => self::CODE], 503);
        }

        return back()->withErrors(['attachment' => $this->getMessage()]);
    }
}
