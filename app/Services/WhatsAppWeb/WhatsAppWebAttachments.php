<?php

namespace App\Services\WhatsAppWeb;

use App\Enums\AttachmentStatus;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Meta\MediaFileName;
use Illuminate\Support\Str;

/**
 * The attachment row of a file that came through WhatsApp Web (QR), the
 * same for a live message, one written on the linked phone and one of the
 * imported history.
 *
 * With a download descriptor the file is pending: the descriptor (it holds
 * the key that decrypts the file) is kept encrypted on the attachment until
 * DownloadWhatsAppWebMedia stores the file. Without one (WhatsApp sent no
 * key, as with "view once" media, or the file is too old to be served) the
 * message still shows that it had a file: the attachment is unavailable
 * with the reason, instead of a message with nothing in it.
 *
 * What WhatsApp declared is kept as a first value and corrected by the
 * download: the type is detected again from the content (MediaStorage), and
 * the pixel size lets the chat reserve the box before the file loads.
 */
final class WhatsAppWebAttachments
{
    /**
     * @param  array<array-key, mixed>  $content  Message content as WhatsAppWebMessagePayload::content() builds it.
     * @param  array<array-key, mixed>|null  $descriptor
     * @param  string  $missingReason  error_code of an attachment without descriptor (expired, historical_missing).
     */
    public static function create(Message $message, array $content, ?array $descriptor, string $missingReason = 'expired'): MessageAttachment
    {
        $descriptor = $descriptor === [] ? null : $descriptor;

        $attachment = new MessageAttachment;
        $attachment->forceFill([
            'message_id' => $message->id,
            'whatsapp_media_id' => null,
            'provider_reference' => $descriptor,
            'disk' => (string) config('filesystems.media_disk', 'local'),
            'path' => null,
            'mime_type' => self::mime($content['mime_type'] ?? null),
            'file_name' => is_string($content['filename'] ?? null) ? MediaFileName::sanitize($content['filename']) : null,
            'width' => is_int($content['width'] ?? null) && is_int($content['height'] ?? null) ? $content['width'] : null,
            'height' => is_int($content['width'] ?? null) && is_int($content['height'] ?? null) ? $content['height'] : null,
            'status' => $descriptor === null ? AttachmentStatus::Unavailable : AttachmentStatus::Pending,
            'error_code' => $descriptor === null ? $missingReason : null,
        ])->save();

        return $attachment;
    }

    /**
     * The declared type without its parameters ("audio/ogg; codecs=opus").
     */
    public static function mime(mixed $declared): string
    {
        $mime = is_string($declared) ? strtolower(trim(explode(';', $declared)[0])) : '';

        return $mime === '' ? 'application/octet-stream' : Str::limit($mime, 127, '');
    }
}
