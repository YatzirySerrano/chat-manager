<?php

namespace App\Http\Controllers\Conversations;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Files of a conversation's messages, served only to people who can see the
 * conversation, from the private media disk. Images, PDFs, audio and video
 * open inline for preview; everything else (or `?download=1`) downloads.
 * The browser is told never to guess another content type, and inline
 * files other than PDFs are sandboxed so they cannot run scripts.
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

    public function show(Request $request, Conversation $conversation, Message $message, MessageAttachment $attachment): Response
    {
        $this->ensureVisible($conversation);

        abort_unless($message->conversation_id === $conversation->id && $attachment->message_id === $message->id, 404);
        abort_if($attachment->path === null || $attachment->path === '', 404, 'El archivo todavía no está disponible.');

        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->path), 404, 'El archivo ya no está disponible.');

        $mime = strtolower($attachment->mime_type);
        $inline = in_array($mime, self::INLINE, true) && ! $request->boolean('download');
        $name = $attachment->file_name ?? 'archivo';

        $headers = [
            'Content-Type' => $inline ? $mime : 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
                $name,
                Str::ascii($name) !== '' ? str_replace(['%', '/', '\\'], '', Str::ascii($name)) : 'archivo',
            ),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
            'Referrer-Policy' => 'no-referrer',
        ];

        if ($mime !== 'application/pdf') {
            $headers['Content-Security-Policy'] = "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox";
        }

        if (config("filesystems.disks.{$attachment->disk}.driver") === 'local') {
            return response()->file($disk->path($attachment->path), $headers);
        }

        return $disk->response($attachment->path, $name, $headers, $inline ? 'inline' : 'attachment');
    }
}
