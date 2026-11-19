<?php

namespace App\Concerns;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Streams a person's profile picture (UserAvatars, always JPEG) from the
 * disk it was saved on, never a redirect, with an ETag and private caching
 * in the viewer's browser only. Callers decide who may see it.
 */
trait ServesUserAvatars
{
    protected function avatarResponse(Request $request, User $user, int $maxAgeSeconds): Response
    {
        abort_unless($user->hasAvatar(), 404);

        $disk = Storage::disk((string) $user->avatar_disk);
        $path = (string) $user->avatar_path;

        try {
            $exists = $disk->exists($path);
            $modifiedAt = $exists ? $disk->lastModified($path) : 0;
        } catch (Throwable $exception) {
            Log::warning('Media storage unavailable while serving a user picture.', ['user_id' => $user->id, 'disk' => $user->avatar_disk, 'exception' => $exception::class]);

            abort(503, 'El almacenamiento de archivos no responde en este momento.');
        }

        abort_unless($exists, 404);

        $etag = sha1(implode('|', ['user-avatar', $user->id, $path, (string) $user->avatar_version]));
        $lastModified = Date::createFromTimestamp($modifiedAt);
        $headers = [
            // UserAvatars always writes JPEG.
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => 'inline; filename="foto-de-perfil.jpg"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];

        $notModified = $this->cachedAvatar(new Response, $etag, $lastModified, $maxAgeSeconds);

        if ($notModified->isNotModified($request)) {
            return $notModified;
        }

        $local = config("filesystems.disks.{$user->avatar_disk}.driver") === 'local';

        $response = $local
            ? response()->file($disk->path($path), $headers)
            : response()->stream(function () use ($disk, $path): void {
                $stream = $disk->readStream($path);

                if (is_resource($stream)) {
                    fpassthru($stream);
                    fclose($stream);
                }
            }, 200, $headers);

        return $this->cachedAvatar($response, $etag, $lastModified, $maxAgeSeconds);
    }

    private function cachedAvatar(Response $response, string $etag, \DateTimeInterface $lastModified, int $maxAgeSeconds): Response
    {
        $response->headers->set('Cache-Control', 'private, max-age='.$maxAgeSeconds);
        $response->setEtag($etag);
        $response->setLastModified($lastModified);

        return $response;
    }
}
