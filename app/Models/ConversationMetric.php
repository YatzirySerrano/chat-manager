<?php

namespace App\Models;

use App\Enums\ConversationStatus;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Derived figures of one conversation for the reports. Never written by
 * hand: App\Services\Analytics\ConversationMetricsCalculator recomputes the
 * whole row from the conversation, its events and its messages.
 *
 * @property int $id
 * @property int $conversation_id
 * @property int $connection_id
 * @property int|null $department_id Current department of the conversation.
 * @property int|null $agent_id Current (or last, once closed) responsible agent.
 * @property ConversationStatus $status
 * @property Carbon $received_at When the conversation was created.
 * @property Carbon|null $first_assigned_at First human attention: acceptance, manual assignment, transfer to an agent or first human reply (automatic distribution excluded).
 * @property Carbon|null $first_human_response_at First agent message accepted by the provider (automatic and failed ones excluded).
 * @property Carbon|null $resolved_at First closing.
 * @property Carbon|null $last_closed_at
 * @property int|null $seconds_to_first_attention
 * @property int|null $seconds_to_first_human_response
 * @property int|null $seconds_to_resolution
 * @property int|null $business_seconds_to_first_human_response Seconds inside the attention hours of the receiving department (frozen once computed).
 * @property int|null $business_seconds_to_resolution Seconds inside the attention hours of the receiving department (frozen once computed).
 * @property int $closed_count
 * @property int $reopened_count
 * @property int $transfers_count
 * @property int $messages_in
 * @property int $messages_out
 * @property int $failed_out
 * @property bool|null $out_of_hours Null when no business hours applied to its department.
 * @property Carbon $computed_at
 */
#[WithoutTimestamps]
class ConversationMetric extends Model
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
            'first_assigned_at' => 'datetime',
            'first_human_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'last_closed_at' => 'datetime',
            'computed_at' => 'datetime',
            'out_of_hours' => 'boolean',
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
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
}
