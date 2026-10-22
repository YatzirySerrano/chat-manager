<?php

namespace App\Models\TeamChat;

use App\Enums\TeamChat\TeamParticipantRole;
use App\Models\User;
use Database\Factories\TeamChat\TeamParticipantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Participation of a person in a thread. Leaving or being removed keeps the
 * row (with `left_at`) as history; access ends at that moment.
 *
 * @property int $id
 * @property int $team_thread_id
 * @property int $user_id
 * @property TeamParticipantRole $role
 * @property Carbon $joined_at
 * @property Carbon|null $left_at
 * @property int|null $added_by_user_id
 * @property int|null $removed_by_user_id
 * @property int|null $last_read_message_id
 * @property bool $muted
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'role', 'joined_at', 'added_by_user_id'])]
class TeamParticipant extends Model
{
    /** @use HasFactory<TeamParticipantFactory> */
    use HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'member',
        'muted' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => TeamParticipantRole::class,
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'muted' => 'boolean',
        ];
    }

    public function isActive(): bool
    {
        return $this->left_at === null;
    }

    public function isAdmin(): bool
    {
        return $this->role === TeamParticipantRole::Admin;
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
