<?php

namespace App\Http\Controllers\TeamChat;

use App\Http\Controllers\Controller;
use App\Models\TeamChat\TeamAttachment;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamThread;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves an internal attachment to a current participant of its thread.
 * There are no public or permanent URLs: every download goes through this
 * route and authorizes again. Only safe images and PDF may be shown inline;
 * everything else is downloaded, and the browser is told never to sniff the
 * content type.
 */
class TeamAttachmentController extends Controller
{
    public function show(Request $request, TeamThread $thread, TeamMessage $message, TeamAttachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $thread);

        abort_if($message->isDeleted() || $attachment->isPurged(), 404);

        $disk = Storage::disk($attachment->disk);

        if ($attachment->path === null || ! $disk->exists($attachment->path)) {
            Log::warning('Falta el archivo de un adjunto del chat interno.', ['attachment_id' => $attachment->id]);

            abort(404);
        }

        $inline = $request->boolean('inline') && $attachment->isPreviewable();
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

        return $inline
            ? $disk->response($attachment->path, $attachment->original_name, $headers, 'inline')
            : $disk->download($attachment->path, $attachment->original_name, $headers);
    }
}
