<?php

namespace App\Models;

use App\Enums\ConversationEventType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only operational event of a conversation.
 *
 * @property int $id
 * @property int $conversation_id
 * @property ConversationEventType $type
 * @property int|null $actor_user_id
 * @property int|null $from_user_id
 * @property int|null $to_user_id
 * @property int|null $from_department_id
 * @property int|null $to_department_id
 * @property string|null $note
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 */
#[Fillable(['conversation_id', 'type', 'actor_user_id', 'from_user_id', 'to_user_id', 'from_department_id', 'to_department_id', 'note', 'metadata', 'created_at'])]
#[WithoutTimestamps]
class ConversationEvent extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ConversationEventType::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
