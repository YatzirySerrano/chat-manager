<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\AttachmentDeliveryMethod;
use App\Enums\AttachmentStatus;
use App\Enums\ConnectionChannel;
use App\Enums\MessageDirection;
use App\Http\Controllers\Controller;
use App\Models\MessageAttachment;
use App\Services\Meta\MediaFileName;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * File of a reply sent through Messenger or Instagram, fetched by Meta from
 * the signed URL the transport put in the Send API call (valid
 * MetaMessagingTransport::MEDIA_URL_MINUTES). Only a valid, unexpired
 * signature reaches it; only available outbound attachments of Messenger
 * or Instagram conversations are served, with their verified type and a
 * Content-Disposition file name that keeps its extension, never cached;
 * anything else is the same 404.
 */
class MetaMediaController extends Controller
{
    public function __invoke(string $attachment): Response
    {
        $file = MessageAttachment::query()
            ->with('message.conversation.channelConnection')
            ->whereKey((int) $attachment)
            ->where('status', AttachmentStatus::Available)
            ->where('delivery_method', AttachmentDeliveryMethod::Native)
            ->first();

        $channel = $file?->message?->conversation?->channelConnection?->channel;

        abort_if($file === null || blank($file->path) || $file->message->direction !== MessageDirection::Outbound
            || ! in_array($channel, [ConnectionChannel::Messenger, ConnectionChannel::Instagram], true), 404);

        $disk = Storage::disk($file->disk);

        abort_unless($disk->exists((string) $file->path), 404);

        // Meta names the customer's file after this header (the URL path
        // carries only the id), so it always has a name with its extension.
        $name = MediaFileName::withExtension($file->file_name, $file->mime_type);
        $headers = [
            'Content-Type' => $file->mime_type,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $name,
                Str::ascii($name) !== '' ? str_replace(['%', '/', '\\'], '', Str::ascii($name)) : 'archivo',
            ),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];

        if (config("filesystems.disks.{$file->disk}.driver") === 'local') {
            return response()->file($disk->path((string) $file->path), $headers);
        }

        return $disk->response((string) $file->path, $name, $headers, 'attachment');
    }
}
