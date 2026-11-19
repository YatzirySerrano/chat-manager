<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ProtectedAccounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The profile picture a person chose (UserAvatars), served to any
 * signed-in person from the disk it was saved on, never a redirect, with
 * private caching in the viewer's browser only. A protected account's
 * picture is a 404 for whoever may not see that account.
 */
class UserAvatarController extends Controller
{
    /** The URL carries the version, so the browser may keep it long. */
    public const BROWSER_CACHE_SECONDS = 604800;

    public function show(Request $request, User $user, ProtectedAccounts $protected): Response
    {
        $viewer = $request->user();

        abort_if($protected->hides($user, $viewer instanceof User ? $viewer : null), 404);
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

        $notModified = $this->cached(new Response, $etag, $lastModified);

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

        return $this->cached($response, $etag, $lastModified);
    }

    private function cached(Response $response, string $etag, \DateTimeInterface $lastModified): Response
    {
        $response->headers->set('Cache-Control', 'private, max-age='.self::BROWSER_CACHE_SECONDS);
        $response->setEtag($etag);
        $response->setLastModified($lastModified);

        return $response;
    }
}
