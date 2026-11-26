<?php

namespace App\Concerns;

use App\Models\MessageAttachment;
use App\Support\Attachments\MediaResponse;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
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
    /**
     * Where the file is read from (LocalMedia: the local copy first, the NAS
     * only while it answers): 404 when it does not exist, 503 when the
     * storage cannot be reached right now.
     *
     * @return array{filesystem: Filesystem, path: string, absolute: string|null, local: bool, remote: bool}
     */
    protected function openStoredFile(MessageAttachment $file): array
    {
        $location = MediaResponse::open((string) $file->disk, (string) $file->path, $file->size_bytes, null, ['attachment_id' => $file->getKey()]);

        abort_if($location === null, 404);

        return $location;
    }

    /**
     * The file itself: sent from this server's disk (the web server's own
     * range support), or streamed from the other disk.
     *
     * @param  array{filesystem: Filesystem, path: string, absolute: string|null, local: bool, remote: bool}  $location
     * @param  array<string, string>  $headers
     */
    protected function sendStoredFile(array $location, string $name, array $headers, string $disposition): Response
    {
        if ($location['absolute'] !== null && ! $location['remote'] && is_readable($location['absolute'])) {
            return response()->file($location['absolute'], $headers);
        }

        $filesystem = $location['filesystem'];

        if ($filesystem instanceof FilesystemAdapter) {
            return $filesystem->response($location['path'], $name, $headers, $disposition);
        }

        MediaResponse::unavailable(MediaResponse::GENERIC);
    }

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

        MediaResponse::unavailable(MediaResponse::GENERIC);
    }
}
