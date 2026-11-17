<?php

namespace App\Models;

use App\Enums\VideoExportStatus;
use App\Models\TeamChat\TeamAttachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One copy of a video to the NAS folder of saved videos, requested from a
 * conversation (message_attachment_id) or from the internal chat
 * (team_attachment_id); exactly one of the two is set. The original file
 * stays where it is, so the chat keeps playing it.
 *
 * `folder` is the folder the person chose, relative to the root of saved
 * videos ("" is the root itself), and `file_name` the name they typed,
 * without extension. `target_path` is the final path on `disk` (with its
 * " (2)" counter, if any) once saved.
 *
 * @property int $id
 * @property int|null $message_attachment_id
 * @property int|null $team_attachment_id
 * @property string $folder
 * @property string $file_name
 * @property string $disk
 * @property string|null $target_path
 * @property VideoExportStatus $status
 * @property string|null $error
 * @property int $attempts
 * @property int|null $user_id
 * @property Carbon|null $saved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class VideoExport extends Model
{
    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'folder' => '',
        'status' => 'queued',
        'attempts' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => VideoExportStatus::class,
            'saved_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<MessageAttachment, $this>
     */
    public function messageAttachment(): BelongsTo
    {
        return $this->belongsTo(MessageAttachment::class);
    }

    /**
     * @return BelongsTo<TeamAttachment, $this>
     */
    public function teamAttachment(): BelongsTo
    {
        return $this->belongsTo(TeamAttachment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The video being copied, whichever chat it came from.
     */
    public function source(): MessageAttachment|TeamAttachment|null
    {
        return $this->message_attachment_id !== null
            ? $this->messageAttachment()->first()
            : $this->teamAttachment()->first();
    }
}
