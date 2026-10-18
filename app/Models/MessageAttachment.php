<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $message_id
 * @property string|null $whatsapp_media_id
 * @property string $disk
 * @property string|null $path
 * @property string $mime_type
 * @property string|null $file_name
 * @property int|null $size_bytes
 * @property string|null $sha256
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['message_id', 'whatsapp_media_id', 'disk', 'path', 'mime_type', 'file_name', 'size_bytes', 'sha256'])]
class MessageAttachment extends Model
{
    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
