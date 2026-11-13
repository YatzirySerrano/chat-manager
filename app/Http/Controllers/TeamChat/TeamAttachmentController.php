<?php

namespace App\Http\Controllers\TeamChat;

use App\Enums\TeamChat\TeamAttachmentKind;
use App\Http\Controllers\Controller;
use App\Models\TeamChat\TeamAttachment;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamThread;
use App\Services\Storage\MediaThumbnails;
use App\Support\Attachments\RangedStream;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves an internal attachment to a current participant of its thread.
 * There are no public or permanent URLs: every download goes through this
 * route and authorizes again. Only safe images and PDF may be shown inline,
 * and audio and video may be played in place (with byte ranges); everything
 * else is downloaded, and the browser is told never to sniff the
 * content type.
 */
class TeamAttachmentController extends Controller
{
    /**
     * Opened on its own tab, a file is an isolated document that may only
     * draw or play itself (the browser's media document needs `media-src`).
     */
    private const SANDBOX = "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox";

    public function show(Request $request, TeamThread $thread, TeamMessage $message, TeamAttachment $attachment, MediaThumbnails $thumbnails): Response
    {
        Gate::authorize('view', $thread);

        abort_if($message->isDeleted() || $attachment->isPurged(), 404);

        $disk = Storage::disk($attachment->disk);

        if ($attachment->path === null || ! $disk->exists($attachment->path)) {
            Log::warning('Falta el archivo de un adjunto del chat interno.', ['attachment_id' => $attachment->id]);

            abort(404);
        }

        /*
         * `?thumb=1`: the small preview of an image the bubble shows
         * (MediaThumbnails keeps it in the system folder of the same private
         * disk and MediaDisk::discard() removes it with the original), so the
         * chat does not download every original to paint the history. Same
         * authorization and headers; whenever no preview can be made (GIF,
         * WebP, transparent PNG, an image already small) the original is
         * answered inline instead.
         */
        $wantsThumbnail = $request->boolean('thumb') && $attachment->isPreviewable() && $attachment->kind === TeamAttachmentKind::Image;

        if ($wantsThumbnail) {
            $thumbnail = $thumbnails->ensure($attachment->disk, $attachment->path, $attachment->mime_type, $attachment->size_bytes);

            if ($thumbnail !== null) {
                return $disk->response($thumbnail['path'], (pathinfo($attachment->original_name, PATHINFO_FILENAME) ?: 'imagen').'.jpg', [
                    'Content-Type' => $thumbnail['mime'],
                    'X-Content-Type-Options' => 'nosniff',
                    'Cache-Control' => 'private, no-store',
                    'Referrer-Policy' => 'no-referrer',
                    'Content-Security-Policy' => self::SANDBOX,
                ], 'inline');
            }
        }

        $playable = $request->boolean('inline') && $attachment->isPlayable();
        $inline = $playable || $wantsThumbnail || ($request->boolean('inline') && $attachment->isPreviewable());
        $headers = [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
        ];

        /*
         * Browsers refuse to render a PDF inside a sandboxed document (their
         * PDF viewer counts as a plugin), so an inline PDF is served without
         * the sandbox; its type is fixed by nosniff and it runs no page script.
         */
        if (! ($inline && $attachment->mime_type === 'application/pdf')) {
            $headers['Content-Security-Policy'] = self::SANDBOX;
        }

        /*
         * Audio and video played in the chat are answered with byte ranges:
         * players seek with them and Safari (every browser on an iPhone)
         * refuses a video served without them. A file on this server or on
         * the mounted NAS answers ranges by itself; a remote one is streamed
         * through RangedStream without being held in memory.
         */
        if ($playable) {
            $headers['Content-Disposition'] = HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_INLINE,
                $attachment->original_name,
                Str::ascii($attachment->original_name) !== '' ? str_replace(['%', '/', '\\'], '', Str::ascii($attachment->original_name)) : 'archivo',
            );

            $response = config("filesystems.disks.{$attachment->disk}.driver") === 'local'
                ? response()->file($disk->path($attachment->path), $headers)
                : RangedStream::response($request, fn () => $disk->readStream($attachment->path), (int) $disk->size($attachment->path), $headers);

            // A file response marks itself public: internal files never are.
            $response->headers->set('Cache-Control', 'private, no-store');

            return $response;
        }

        return $inline
            ? $disk->response($attachment->path, $attachment->original_name, $headers, 'inline')
            : $disk->download($attachment->path, $attachment->original_name, $headers);
    }
}
