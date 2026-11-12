<?php

namespace App\Http\Controllers\TeamChat;

use App\Http\Controllers\Controller;
use App\Models\TeamChat\TeamAttachment;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamThread;
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
    public function show(Request $request, TeamThread $thread, TeamMessage $message, TeamAttachment $attachment): Response
    {
        Gate::authorize('view', $thread);

        abort_if($message->isDeleted() || $attachment->isPurged(), 404);

        $disk = Storage::disk($attachment->disk);

        if ($attachment->path === null || ! $disk->exists($attachment->path)) {
            Log::warning('Falta el archivo de un adjunto del chat interno.', ['attachment_id' => $attachment->id]);

            abort(404);
        }

        $playable = $request->boolean('inline') && $attachment->isPlayable();
        $inline = $playable || ($request->boolean('inline') && $attachment->isPreviewable());
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
            $headers['Content-Security-Policy'] = "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox";
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
