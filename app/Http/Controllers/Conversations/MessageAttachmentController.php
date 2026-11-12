<?php

namespace App\Http\Controllers\Conversations;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Storage\MediaThumbnails;
use App\Support\Attachments\RangedStream;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Files of a conversation's messages, served only to people who can see the
 * conversation, from the private media disk. Images, PDFs, audio and video
 * open inline for preview; everything else (or `?download=1`) downloads.
 * The browser is told never to guess another content type, and inline
 * files other than PDFs are sandboxed so they cannot run scripts.
 *
 * Files on an external disk are streamed through the application (never a
 * redirect to a public or pre-signed bucket URL), so these headers always
 * apply; an unreachable disk answers 503 instead of a server error.
 */
class MessageAttachmentController extends ConversationOperationController
{
    /**
     * Content types the browser may render inside the inbox.
     *
     * @var list<string>
     */
    private const INLINE = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        'application/pdf',
        'audio/aac', 'audio/mp4', 'audio/mpeg', 'audio/amr', 'audio/ogg', 'audio/opus', 'video/ogg', 'application/ogg',
        'video/mp4', 'video/3gpp',
    ];

    public function show(Request $request, Conversation $conversation, Message $message, MessageAttachment $attachment, MediaThumbnails $thumbnails): Response
    {
        $this->ensureVisible($conversation);

        abort_unless($message->conversation_id === $conversation->id && $attachment->message_id === $message->id, 404);
        abort_if($attachment->path === null || $attachment->path === '', 404, 'El archivo todavía no está disponible.');

        // Each file is read from the disk it was saved on (a row on "local"
        // keeps working after MEDIA_DISK changes).
        $disk = Storage::disk($attachment->disk);
        $path = $attachment->path;
        $mime = strtolower($attachment->mime_type);
        $variant = 'original';

        // `?thumb=1`: the small preview the chat bubble shows (MediaThumbnails).
        // Same authorization, headers and revalidation as the original, and
        // the original itself whenever no preview can be made.
        if ($request->boolean('thumb') && ! $request->boolean('download')) {
            $thumbnail = $thumbnails->ensure($attachment->disk, $path, $mime, $attachment->size_bytes);

            if ($thumbnail !== null) {
                $path = $thumbnail['path'];
                $mime = $thumbnail['mime'];
                $variant = 'thumb';
                $this->rememberDimensions($attachment, $thumbnail['width'], $thumbnail['height']);
            }
        }

        try {
            $exists = $disk->exists($path);
            $modifiedAt = $exists ? $disk->lastModified($path) : 0;
            $local = config("filesystems.disks.{$attachment->disk}.driver") === 'local';
            $size = $exists && ! $local ? (int) $disk->size($path) : 0;
        } catch (Throwable $exception) {
            Log::warning('Media storage unavailable while serving an attachment.', ['attachment_id' => $attachment->getKey(), 'disk' => $attachment->disk, 'exception' => $exception::class]);

            abort(503, 'El almacenamiento de archivos no responde en este momento. Inténtalo de nuevo en unos minutos.');
        }

        abort_unless($exists, 404, 'El archivo ya no está disponible.');

        $inline = in_array($mime, self::INLINE, true) && ! $request->boolean('download');
        $name = $attachment->file_name ?? 'archivo';

        $lastModified = Date::createFromTimestamp($modifiedAt);
        $etag = sha1(implode('|', [$attachment->getKey(), $path, $lastModified->getTimestamp(), $mime, $inline ? 'inline' : 'attachment', $variant]));

        $headers = [
            'Content-Type' => $inline ? $mime : 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
                $name,
                Str::ascii($name) !== '' ? str_replace(['%', '/', '\\'], '', Str::ascii($name)) : 'archivo',
            ),
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ];

        if ($mime !== 'application/pdf') {
            $headers['Content-Security-Policy'] = "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox";
        }

        $notModified = $this->revalidated(new Response, $etag, $lastModified);

        if ($notModified->isNotModified($request)) {
            return $notModified;
        }

        // A local file answers byte ranges by itself; a remote one is streamed
        // through RangedStream, so a video can be sought (and plays in Safari,
        // which only accepts ranged answers) without holding it in memory.
        $response = $local
            ? response()->file($disk->path($path), $headers)
            : RangedStream::response($request, fn () => $disk->readStream($path), $size, $headers);

        return $this->revalidated($response, $etag, $lastModified);
    }

    /**
     * Keeps the size of an image the first time it is measured, so the chat
     * reserves its box before the file loads.
     */
    private function rememberDimensions(MessageAttachment $attachment, ?int $width, ?int $height): void
    {
        if ($width === null || $height === null || $attachment->getAttribute('width') !== null) {
            return;
        }

        try {
            // Not an edit of the attachment: its timestamps stay as they are.
            DB::table('message_attachments')->where('id', $attachment->getKey())->whereNull('width')->update(['width' => $width, 'height' => $height]);
        } catch (Throwable) {
            // Only a layout hint: never a reason to fail the file.
        }
    }

    /**
     * Private, always revalidated caching. Set after the response is built
     * because a file response marks itself public on construction.
     */
    private function revalidated(Response $response, string $etag, CarbonInterface $lastModified): Response
    {
        $response->headers->set('Cache-Control', 'private, no-cache');
        $response->setEtag($etag);
        $response->setLastModified($lastModified);

        return $response;
    }
}
