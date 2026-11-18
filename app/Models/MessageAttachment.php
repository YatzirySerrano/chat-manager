<?php

namespace App\Models;

use App\Enums\AttachmentDeliveryMethod;
use App\Enums\AttachmentStatus;
use App\Jobs\WarmMediaThumbnail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $message_id
 * @property string|null $whatsapp_media_id
 * @property array<array-key, mixed>|null $provider_reference Encrypted download descriptor of a WhatsApp Web (QR) file; never serialized.
 * @property string $disk
 * @property string|null $path
 * @property string $mime_type
 * @property string|null $file_name
 * @property int|null $size_bytes
 * @property int|null $width Pixels of an image (upright), once measured (WarmMediaThumbnail or its preview).
 * @property int|null $height
 * @property string|null $sha256
 * @property AttachmentStatus $status
 * @property AttachmentDeliveryMethod $delivery_method Native file of the channel or secure download link.
 * @property string|null $error_code Why the file is unavailable (expired, too_large, type_not_allowed...).
 * @property int $download_attempts
 * @property Carbon|null $downloaded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['message_id', 'whatsapp_media_id', 'disk', 'path', 'mime_type', 'file_name', 'size_bytes', 'sha256', 'delivery_method'])]
#[Hidden(['provider_reference'])]
class MessageAttachment extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'available',
        'delivery_method' => 'native',
        'download_attempts' => 0,
    ];

    /**
     * As soon as a stored image becomes available (received, sent or
     * imported), its chat preview is made in the background, so nobody waits
     * for it the first time the conversation is opened.
     */
    protected static function booted(): void
    {
        static::saved(function (MessageAttachment $attachment): void {
            if ($attachment->status === AttachmentStatus::Available
                && ($attachment->wasRecentlyCreated || $attachment->wasChanged(['path', 'status']))) {
                WarmMediaThumbnail::warm((string) $attachment->disk, $attachment->path, (string) $attachment->mime_type, $attachment->size_bytes, 'message_attachments', $attachment->id);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AttachmentStatus::class,
            // Download descriptor of a WhatsApp Web file (it holds a media key).
            'provider_reference' => 'encrypted:array',
            'delivery_method' => AttachmentDeliveryMethod::class,
            'size_bytes' => 'integer',
            'download_attempts' => 'integer',
            'downloaded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
