<?php

namespace App\Models;

use App\Enums\ConversationStatus;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Work of one person in one conversation, for the per-agent reports. Never
 * written by hand: App\Services\Analytics\AgentAttributionCalculator rebuilds
 * every row of a conversation from its events and its messages.
 *
 * @property int $id
 * @property int $conversation_id
 * @property int $user_id
 * @property int $connection_id
 * @property int|null $department_id Current department of the conversation.
 * @property int|null $category_id Current category of the conversation.
 * @property ConversationStatus $status Current status of the conversation.
 * @property Carbon $received_at When the conversation was created.
 * @property bool $was_responsible Whether the person was ever responsible for it.
 * @property bool $responsible_now Whether the person is responsible for it now (and it is not closed).
 * @property Carbon|null $first_responsible_at
 * @property Carbon|null $attended_at First human attention by the person: accepting it, receiving it by a manual assignment or a transfer, or replying (the automatic distribution alone is not attention).
 * @property int $handling_seconds Sum of the finished intervals of responsibility.
 * @property int $intervals_count Finished and running intervals of responsibility.
 * @property Carbon|null $open_since Start of the interval still running (null when none, or when the history does not tell).
 * @property int $replies_count Human replies accepted by the provider (automatic ones excluded).
 * @property Carbon|null $first_reply_at
 * @property bool $authored_first_response Whether the person wrote the first human response of the conversation.
 * @property int|null $first_response_seconds Delay of that first response (only for its author).
 * @property int $closes_count Closings of the conversation while the person was responsible.
 * @property Carbon|null $last_closed_at
 * @property int $transfers_in
 * @property int $transfers_out
 * @property Carbon $computed_at
 */
#[WithoutTimestamps]
class ConversationAgentMetric extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'received_at' => 'datetime',
            'was_responsible' => 'boolean',
            'responsible_now' => 'boolean',
            'first_responsible_at' => 'datetime',
            'attended_at' => 'datetime',
            'open_since' => 'datetime',
            'first_reply_at' => 'datetime',
            'authored_first_response' => 'boolean',
            'last_closed_at' => 'datetime',
            'computed_at' => 'datetime',
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
