<?php

namespace App\Models;

use App\Enums\ResponseCycleKind;
use Database\Factories\ResponseCycleFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One "the customer wrote → the team must answer" cycle of a conversation.
 * A fact: the objective in force when the cycle started is frozen in the
 * row, so later rule changes never rewrite it, and rows are never deleted.
 *
 * At most one cycle per conversation is open: `active` is 1 while open and
 * null once ended, under a unique (conversation_id, active).
 *
 * @property int $id
 * @property int $conversation_id
 * @property ResponseCycleKind $kind
 * @property int $connection_id
 * @property int|null $department_id Department of the conversation when the cycle started.
 * @property int|null $category_id Category of the conversation when the cycle started.
 * @property int|null $assigned_user_id Responsible agent when the cycle started (or when it was assigned during the cycle).
 * @property Carbon $started_at The customer message that opened the cycle.
 * @property int|null $target_seconds Null = no objective configured: the cycle is not measured against a deadline.
 * @property bool $business_hours_only Whether the objective counts only business time.
 * @property Carbon|null $warn_at When the cycle becomes «próxima a vencer».
 * @property Carbon|null $due_at Deadline.
 * @property Carbon|null $warned_at When the warning was raised.
 * @property Carbon|null $breached_at When the deadline was found exceeded.
 * @property int|null $breach_user_id Responsible agent at the moment of the breach.
 * @property Carbon|null $responded_at
 * @property int|null $responder_user_id
 * @property Carbon|null $ended_at
 * @property string|null $outcome `answered` or `closed`.
 * @property int|null $response_seconds Calendar seconds from the start to the answer.
 * @property int|null $business_response_seconds Business seconds from the start to the answer.
 * @property int|null $exceeded_seconds Seconds over the objective, in the unit of the objective.
 * @property int $reminders_count
 * @property Carbon|null $last_alert_at
 * @property int|null $active 1 while open, null once ended.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ResponseCycle extends Model
{
    /** @use HasFactory<ResponseCycleFactory> */
    use HasFactory;

    public const OUTCOME_ANSWERED = 'answered';

    public const OUTCOME_CLOSED = 'closed';

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'business_hours_only' => true,
        'reminders_count' => 0,
        'active' => 1,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ResponseCycleKind::class,
            'started_at' => 'datetime',
            'target_seconds' => 'integer',
            'business_hours_only' => 'boolean',
            'warn_at' => 'datetime',
            'due_at' => 'datetime',
            'warned_at' => 'datetime',
            'breached_at' => 'datetime',
            'responded_at' => 'datetime',
            'ended_at' => 'datetime',
            'response_seconds' => 'integer',
            'business_response_seconds' => 'integer',
            'exceeded_seconds' => 'integer',
            'reminders_count' => 'integer',
            'last_alert_at' => 'datetime',
            'active' => 'integer',
        ];
    }

    public function isActive(): bool
    {
        return $this->active === 1;
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<ConversationCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ConversationCategory::class, 'category_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responder_user_id');
    }

    /**
     * Cycles still waiting for the team's answer.
     *
     * @param  Builder<ResponseCycle>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where($query->qualifyColumn('active'), 1);
    }

    /**
     * Cycles whose deadline was exceeded (open or already ended).
     *
     * @param  Builder<ResponseCycle>  $query
     */
    #[Scope]
    protected function breached(Builder $query): void
    {
        $query->whereNotNull($query->qualifyColumn('breached_at'));
    }
}
