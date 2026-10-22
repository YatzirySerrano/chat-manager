<?php

namespace App\Models\TeamChat;

use App\Enums\TeamChat\TeamAttachmentKind;
use Database\Factories\TeamChat\TeamAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A file attached to an internal message, stored on a private disk under a
 * random name. It is only served through the authorized download route.
 *
 * @property int $id
 * @property int $team_message_id
 * @property string $disk
 * @property string|null $path
 * @property string $original_name
 * @property string $mime_type
 * @property TeamAttachmentKind $kind
 * @property int $size_bytes
 * @property string $sha256
 * @property Carbon|null $purged_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['disk', 'path', 'original_name', 'mime_type', 'kind', 'size_bytes', 'sha256'])]
class TeamAttachment extends Model
{
    /** @use HasFactory<TeamAttachmentFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => TeamAttachmentKind::class,
            'size_bytes' => 'integer',
            'purged_at' => 'datetime',
        ];
    }

    public function isPurged(): bool
    {
        return $this->purged_at !== null || $this->path === null;
    }

    /**
     * Whether browsers may display it inline (safe images and PDF).
     */
    public function isPreviewable(): bool
    {
        return in_array($this->mime_type, (array) config('team-chat.attachments.inline_types', []), true);
    }

    /**
     * @return BelongsTo<TeamMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(TeamMessage::class, 'team_message_id');
    }
}
