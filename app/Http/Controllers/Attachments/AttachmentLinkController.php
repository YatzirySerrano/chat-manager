<?php

namespace App\Http\Controllers\Attachments;

use App\Enums\AttachmentDeliveryMethod;
use App\Enums\AttachmentStatus;
use App\Enums\MessageDirection;
use App\Http\Controllers\Controller;
use App\Models\MessageAttachment;
use App\Support\Attachments\AttachmentDownloadLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public download of a file sent to a customer as a secure link (ZIP on
 * WhatsApp, Messenger or Instagram; see AttachmentDownloadLink). Only a URL
 * signed by this server and not yet expired reaches it (`signed`
 * middleware, rate limited per address). The file is looked up by its id
 * and served from its stored path on the private disk, never from anything
 * in the URL; only outbound attachments delivered as a link and still
 * available are served, everything else is the same 404; a link revoked by
 * the agent answers 410 with a Spanish page. The file always
 * downloads, the browser never guesses its type and nothing is cached by
 * intermediaries. Every download is logged (ids only, plus a hash of the
 * address).
 */
class AttachmentLinkController extends Controller
{
    public function __invoke(Request $request, string $attachment): Response
    {
        $file = MessageAttachment::query()
            ->with('message:id,conversation_id,direction,metadata')
            ->whereKey((int) $attachment)
            ->where('delivery_method', AttachmentDeliveryMethod::Link)
            ->where('status', AttachmentStatus::Available)
            ->first();

        abort_if($file === null || $file->path === null || $file->path === '' || $file->message?->direction !== MessageDirection::Outbound, 404);

        // Revoked by the agent (RevokeAttachmentLink): gone for good.
        abort_if(AttachmentDownloadLink::isRevoked($file->message), 410);

        $disk = Storage::disk($file->disk);

        abort_unless($disk->exists($file->path), 404);

        Log::info('Descarga de un archivo enviado como enlace seguro.', [
            'attachment_id' => $file->id,
            'message_id' => $file->message_id,
            'conversation_id' => $file->message->conversation_id,
            'ip_hash' => substr(hash('sha256', (string) $request->ip()), 0, 16),
        ]);

        $name = $file->file_name ?? 'archivo';
        $headers = [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $name,
                Str::ascii($name) !== '' ? str_replace(['%', '/', '\\'], '', Str::ascii($name)) : 'archivo',
            ),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];

        if (config("filesystems.disks.{$file->disk}.driver") === 'local') {
            return response()->file($disk->path($file->path), $headers);
        }

        return $disk->response($file->path, $name, $headers, 'attachment');
    }
}
