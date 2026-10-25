<?php

namespace App\Support\Attachments;

use App\Enums\AttachmentDeliveryMethod;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;

/**
 * Secure download links of files a channel cannot carry natively (ZIP on
 * WhatsApp, Messenger and Instagram). The file stays on the private media
 * disk; the customer receives a text with a signed URL of the public
 * `attachments.link` route that expires `attachments.link_ttl_days` days
 * after the reply was queued. The expiry is stored on the message
 * (metadata.download_link.expires_at), so every delivery attempt sends the
 * very same URL and the inbox can show until when it works.
 *
 * Transports of every channel build the outbound text with text().
 *
 * A link can be revoked before it expires (RevokeAttachmentLink): the
 * moment is stored as metadata.download_link.revoked_at, the public route
 * then answers 410 and no transport sends that link again.
 */
class AttachmentDownloadLink
{
    public const METADATA_KEY = 'download_link';

    public function __construct(private PlatformSettings $settings) {}

    /**
     * When a link queued now stops working.
     */
    public static function newExpiry(): CarbonImmutable
    {
        return CarbonImmutable::now()->addDays(max(1, (int) config('attachments.link_ttl_days', 7)));
    }

    /**
     * The link-delivered attachment of the message, if it has one.
     */
    public static function attachmentOf(Message $message): ?MessageAttachment
    {
        $attachment = $message->attachments->first();

        return $attachment instanceof MessageAttachment && $attachment->delivery_method === AttachmentDeliveryMethod::Link ? $attachment : null;
    }

    public static function expiresAt(Message $message): ?CarbonImmutable
    {
        $value = is_array($message->metadata) ? ($message->metadata[self::METADATA_KEY]['expires_at'] ?? null) : null;

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }

    public static function isExpired(Message $message): bool
    {
        $expiresAt = self::expiresAt($message);

        return $expiresAt === null || $expiresAt->isPast();
    }

    public static function revokedAt(Message $message): ?CarbonImmutable
    {
        $value = is_array($message->metadata) ? ($message->metadata[self::METADATA_KEY]['revoked_at'] ?? null) : null;

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }

    public static function isRevoked(Message $message): bool
    {
        return self::revokedAt($message) !== null;
    }

    public function url(MessageAttachment $attachment, CarbonImmutable $expiresAt): string
    {
        return URL::temporarySignedRoute('attachments.link', $expiresAt, ['attachment' => $attachment->id]);
    }

    /**
     * The text the customer receives: the agent's caption, the file's name
     * and size, and the download URL with its expiry date. With $maxBytes
     * (Instagram: 1000 bytes) a longer text is rebuilt in a compact form and
     * the file name shortened; the caption is never cut, so the result may
     * still exceed the limit and the transport then refuses it.
     */
    public function text(Message $message, MessageAttachment $attachment, ?int $maxBytes = null): string
    {
        $expiresAt = self::expiresAt($message) ?? self::newExpiry();
        $timezone = (string) $this->settings->get('general.timezone', 'America/Mexico_City');
        $caption = trim((string) $message->body);
        $size = $attachment->size_bytes !== null ? ' ('.ChannelAttachmentRules::humanSize($attachment->size_bytes).')' : '';
        $name = $attachment->file_name ?? 'archivo.zip';
        $date = $expiresAt->setTimezone($timezone)->format('d/m/Y');
        $url = $this->url($attachment, $expiresAt);

        $lines = array_filter([
            $caption,
            sprintf('Archivo: %s%s', $name, $size),
            sprintf('Descárgalo con este enlace seguro (vence el %s): %s', $date, $url),
        ], fn (string $line): bool => $line !== '');

        $text = implode("\n\n", $lines);

        if ($maxBytes === null || strlen($text) <= $maxBytes) {
            return $text;
        }

        $compact = fn (string $fileName): string => implode("\n", array_filter([
            $caption,
            sprintf('%s%s, enlace seguro hasta el %s:', $fileName, $size, $date),
            $url,
        ], fn (string $line): bool => $line !== ''));

        $overflow = strlen($compact($name)) - $maxBytes;

        if ($overflow > 0 && strlen($name) > 24) {
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $keep = max(8, strlen($name) - $overflow - 8);
            $name = rtrim(mb_strcut($name, 0, $keep, 'UTF-8')).'…'.($extension !== '' ? '.'.$extension : '');
        }

        return $compact($name);
    }
}
