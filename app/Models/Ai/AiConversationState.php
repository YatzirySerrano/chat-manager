<?php

namespace App\Models\Ai;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * What the assistant did in one conversation during its current cycle
 * (reopen_count): how many replies it sent and whether it already handed
 * the conversation to a person. A reopened conversation starts over
 * (forCycle()).
 *
 * @property int $id
 * @property int $conversation_id
 * @property int $cycle
 * @property int $replies
 * @property string|null $handoff_reason
 * @property Carbon|null $handed_off_at
 * @property Carbon|null $last_reply_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiConversationState extends Model
{
    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'cycle' => 0,
        'replies' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cycle' => 'integer',
            'replies' => 'integer',
            'handed_off_at' => 'datetime',
            'last_reply_at' => 'datetime',
        ];
    }

    /**
     * The state of a conversation in a cycle, reset when the stored one
     * belongs to an earlier cycle. Not saved.
     */
    public static function forCycle(int $conversationId, int $cycle, bool $lock = false): self
    {
        $state = self::query()->where('conversation_id', $conversationId)->when($lock, fn ($query) => $query->lockForUpdate())->first()
            ?? new self;

        $state->forceFill(['conversation_id' => $conversationId]);

        if ($state->cycle !== $cycle) {
            $state->forceFill(['cycle' => $cycle, 'replies' => 0, 'handoff_reason' => null, 'handed_off_at' => null]);
        }

        return $state;
    }

    public function handedOff(): bool
    {
        return $this->handed_off_at !== null;
    }
}
