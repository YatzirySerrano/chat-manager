<?php

namespace App\Http\Controllers\WebChat\Api;

use App\Enums\AttachmentStatus;
use App\Enums\MessageDirection;
use App\Http\Controllers\Controller;
use App\Models\MessageAttachment;
use App\Models\WebChatSession;
use App\Services\WebChat\VisitorSessions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Files the agents sent to a visitor, behind short-lived signed URLs
 * (VisitorMessageResource) bound to the visitor's session ("s"). The
 * session must still be usable and the file must belong to an outbound
 * message the session can see; anything else is the same 404. Images open
 * inline; every other type downloads. The browser never guesses the type
 * and the file is sandboxed, so it cannot run scripts.
 */
class VisitorAttachmentController extends Controller
{
    /** @var list<string> */
    private const INLINE = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function show(Request $request, VisitorSessions $sessions, string $attachment): Response
    {
        $session = WebChatSession::query()->with('connection')->where('public_id', (string) $request->query('s'))->first();

        abort_if($session === null || ! $session->isUsable() || $session->connection === null || $session->connection->isDeleted(), 404);

        $file = MessageAttachment::query()
            ->whereKey((int) $attachment)
            ->where('status', AttachmentStatus::Available)
            ->whereIn('message_id', $sessions->visibleMessages($session)->select('id')->where('direction', MessageDirection::Outbound))
            ->first();

        abort_if($file === null || $file->path === null || $file->path === '', 404);

        $disk = Storage::disk($file->disk);

        abort_unless($disk->exists($file->path), 404);

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

        if (config("filesystems.disks.{$file->disk}.driver") === 'local') {
            return response()->file($disk->path($file->path), $headers);
        }

        return $disk->response($file->path, $name, $headers, $inline ? 'inline' : 'attachment');
    }
}
