<?php

namespace App\Concerns;

use App\Models\MessageAttachment;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Serving a stored attachment when the storage itself fails (an external
 * disk that does not answer, expired credentials): a controlled 503 with a
 * plain explanation and a warning without customer data, never a 500 that
 * looks like the file is broken. A file that is simply missing is still a
 * 404 (the callers check exists() outside the protected call).
 */
trait ServesStoredAttachments
{
    protected function storedFileExists(Filesystem $disk, MessageAttachment $file): bool
    {
        return $this->guardingStorage($file, fn (): bool => $disk->exists((string) $file->path));
    }

    /**
     * @param  Closure(): Response  $respond
     */
    protected function storedFileResponse(MessageAttachment $file, Closure $respond): Response
    {
        return $this->guardingStorage($file, $respond);
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function guardingStorage(MessageAttachment $file, Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            Log::warning('Media storage unavailable while serving an attachment.', ['attachment_id' => $file->getKey(), 'disk' => $file->disk, 'exception' => $exception::class]);
        }

        abort(503, 'El almacenamiento de archivos no responde en este momento. Inténtalo de nuevo en unos minutos.');
    }
}
