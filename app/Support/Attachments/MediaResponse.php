<?php

namespace App\Support\Attachments;

use App\Exceptions\StorageUnavailable;
use App\Services\Storage\LocalMedia;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The body of an authorized media response, from wherever LocalMedia found
 * the file (the caller has already checked permissions and built the
 * headers):
 *
 * - a file on this server's disk is sent by the web server itself through
 *   X-Accel-Redirect when MEDIA_X_ACCEL_PREFIX is configured (an "internal"
 *   nginx location aliased to the private local folder: PHP-FPM is freed at
 *   once and nginx answers byte ranges), else by PHP (BinaryFileResponse,
 *   which also answers ranges);
 * - a file only on the NAS is streamed in chunks with byte ranges
 *   (RangedStream), never loaded whole in memory.
 */
final class MediaResponse
{
    /**
     * @param  array{filesystem: Filesystem, path: string, absolute: string|null, local: bool, remote: bool}  $file
     * @param  array<string, string>  $headers
     */
    public static function make(Request $request, array $file, array $headers, ?int $size = null): Response
    {
        if ($file['absolute'] !== null && ! $file['remote']) {
            $accel = self::accelPath($file['absolute']);

            if ($accel !== null) {
                return new Response('', 200, [...$headers, 'X-Accel-Redirect' => $accel]);
            }

            return response()->file($file['absolute'], $headers);
        }

        $filesystem = $file['filesystem'];
        $path = $file['path'];
        $size ??= (int) $filesystem->size($path);

        return RangedStream::response($request, fn () => $filesystem->readStream($path), $size, $headers);
    }

    /**
     * Opens a file for an HTTP answer: null when it does not exist; a 503
     * (with Retry-After) when it is only on a NAS that is not answering or the
     * storage failed, instead of the request hanging.
     *
     * @param  array<string, scalar|null>  $context
     * @return array{filesystem: Filesystem, path: string, absolute: string|null, local: bool, remote: bool}|null
     */
    public static function open(string $disk, string $path, ?int $sizeBytes = null, ?string $mime = null, array $context = []): ?array
    {
        try {
            return app(LocalMedia::class)->open($disk, $path, true, $sizeBytes, $mime);
        } catch (StorageUnavailable $exception) {
            abort(503, $exception->reason === 'remote_unavailable' ? $exception->getMessage() : 'El almacenamiento de archivos no responde en este momento. Inténtalo de nuevo en unos minutos.', ['Retry-After' => '30']);
        } catch (Throwable $exception) {
            Log::warning('Media storage unavailable while serving a file.', [...$context, 'disk' => $disk, 'exception' => $exception::class]);

            abort(503, 'El almacenamiento de archivos no responde en este momento. Inténtalo de nuevo en unos minutos.', ['Retry-After' => '30']);
        }
    }

    /**
     * The internal nginx URI of a file of the local media folder, or null
     * when X-Accel-Redirect is not configured or the file is elsewhere.
     */
    private static function accelPath(string $absolute): ?string
    {
        $prefix = config('attachments.storage.local_first.x_accel_prefix');

        if (! is_string($prefix) || $prefix === '') {
            return null;
        }

        $root = rtrim(app(LocalMedia::class)->root(), '/').'/';

        if (! str_starts_with($absolute, $root)) {
            return null;
        }

        $relative = substr($absolute, strlen($root));

        return rtrim($prefix, '/').'/'.implode('/', array_map('rawurlencode', explode('/', $relative)));
    }
}
