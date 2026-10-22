<?php

namespace App\Models\TeamChat;

use App\Enums\TeamChat\TeamMessageKind;
use App\Models\User;
use Database\Factories\TeamChat\TeamMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A message of an internal thread. It is never physically deleted: when its
 * author deletes it the content is erased and the row remains as a
 * "Mensaje eliminado" marker.
 *
 * @property int $id
 * @property int $team_thread_id
 * @property int|null $user_id
 * @property TeamMessageKind $kind
 * @property string|null $body
 * @property array<string, mixed>|null $metadata
 * @property int|null $reply_to_message_id
 * @property Carbon|null $edited_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'kind', 'body', 'metadata', 'reply_to_message_id'])]
class TeamMessage extends Model
{
    /** @use HasFactory<TeamMessageFactory> */
    use HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'kind' => 'message',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => TeamMessageKind::class,
            'metadata' => 'array',
            'edited_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    public function isSystem(): bool
    {
        return $this->kind === TeamMessageKind::System;
    }

    /**
     * @return BelongsTo<TeamThread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(TeamThread::class, 'team_thread_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<TeamMessage, $this>
     */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(TeamMessage::class, 'reply_to_message_id');
    }

    /**
     * @return HasMany<TeamAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(TeamAttachment::class);
    }
}
