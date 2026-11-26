<?php

namespace App\Support\Attachments;

use App\Jobs\DownloadInboundMedia;
use App\Jobs\DownloadTelegramMedia;
use App\Jobs\DownloadWhatsAppWebMedia;
use App\Models\MessageAttachment;

/**
 * The download job of a customer file by where it came from: Telegram from
 * the Bot API, WhatsApp Web (QR) from the private Node service with the
 * descriptor kept on the row, the rest from Meta. Shared by the reception
 * and the recovery commands, so a retried file never goes to a job that
 * cannot fetch it (and silently leaves it "pending").
 */
final class MediaDownloads
{
    /**
     * Whether a job can still fetch the file (it keeps what the job needs).
     */
    public static function recoverable(MessageAttachment $attachment): bool
    {
        $connection = $attachment->message?->conversation?->channelConnection;

        if ($connection?->isTelegram() === true) {
            return $attachment->provider_reference !== null;
        }

        if ($attachment->provider_reference !== null) {
            return true;
        }

        return ($attachment->whatsapp_media_id !== null && ! str_starts_with($attachment->whatsapp_media_id, 'sim.'))
            || DownloadInboundMedia::sourceUrl($attachment) !== null;
    }

    public static function dispatch(MessageAttachment $attachment): void
    {
        $connection = $attachment->message?->conversation?->channelConnection;

        match (true) {
            $connection?->isTelegram() === true => DownloadTelegramMedia::dispatch($attachment->id),
            $attachment->provider_reference !== null => DownloadWhatsAppWebMedia::dispatch($attachment->id),
            default => DownloadInboundMedia::dispatch($attachment->id),
        };
    }
}
