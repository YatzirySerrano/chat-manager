<?php

namespace App\Http\Controllers\WebChat\Api;

use App\Concerns\ServesStoredAttachments;
use App\Enums\AttachmentStatus;
use App\Enums\MessageDirection;
use App\Http\Controllers\Controller;
use App\Models\MessageAttachment;
use App\Models\WebChatSession;
use App\Services\WebChat\VisitorSessions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Files of a visitor's conversation (the ones the agents sent and the ones
 * the visitor uploaded from the widget, "web.in.*"; never other inbound
 * media), behind short-lived signed URLs
 * (VisitorMessageResource) bound to the visitor's session ("s"). The
 * session must still be usable and the file must belong to a message the
 * session can see; anything else is the same 404. Images, audio and video
 * are served inline with their real type (so the widget can show and play
 * them); every other type downloads. The browser never guesses the type
 * and the file is sandboxed, so it cannot run scripts.
 */
class VisitorAttachmentController extends Controller
{
    use ServesStoredAttachments;

    /** @var list<string> */
    private const INLINE = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        'audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/ogg', 'audio/wav', 'audio/webm',
        'video/mp4', 'video/webm', 'video/ogg', 'video/quicktime',
    ];

    public function show(Request $request, VisitorSessions $sessions, string $attachment): Response
    {
        $session = WebChatSession::query()->with('connection')->where('public_id', (string) $request->query('s'))->first();

        abort_if($session === null || ! $session->isUsable() || $session->connection === null || $session->connection->isDeleted(), 404);

        $file = MessageAttachment::query()
            ->whereKey((int) $attachment)
            ->where('status', AttachmentStatus::Available)
            ->whereIn('message_id', $sessions->visibleMessages($session)->select('id')->where(fn (Builder $own) => $own
                ->where('direction', MessageDirection::Outbound)
                ->orWhere('whatsapp_message_id', 'like', VisitorSessions::WIDGET_PREFIX.'%')))
            ->first();

        abort_if($file === null || $file->path === null || $file->path === '', 404);

        $stored = $this->openStoredFile($file);

        $mime = strtolower($file->mime_type);
        $inline = in_array($mime, self::INLINE, true);
        $name = $file->file_name ?? 'archivo';

        $headers = [
            'Content-Type' => $inline ? $mime : 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
                $name,
                Str::ascii($name) !== '' ? str_replace(['%', '/', '\\'], '', Str::ascii($name)) : 'archivo',
            ),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Referrer-Policy' => 'no-referrer',
        ];

        return $this->storedFileResponse($file, fn (): Response => $this->sendStoredFile($stored, $name, $headers, $inline ? 'inline' : 'attachment'));
    }
}
