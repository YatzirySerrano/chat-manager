<?php

namespace App\Models\TeamChat;

use App\Enums\TeamChat\TeamThreadType;
use App\Models\User;
use App\Policies\TeamChat\TeamThreadPolicy;
use Database\Factories\TeamChat\TeamThreadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An internal chat: a one-to-one conversation between two employees or a
 * group. It never shares entities with customer conversations. Threads are
 * never deleted; groups can be archived (read-only).
 *
 * @property int $id
 * @property TeamThreadType $type
 * @property string|null $name
 * @property string|null $description
 * @property string|null $direct_key
 * @property int|null $created_by_user_id
 * @property Carbon|null $archived_at
 * @property int|null $archived_by_user_id
 * @property int|null $last_message_id
 * @property Carbon|null $last_message_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UsePolicy(TeamThreadPolicy::class)]
#[Fillable(['type', 'name', 'description'])]
class TeamThread extends Model
{
    /** @use HasFactory<TeamThreadFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TeamThreadType::class,
            'archived_at' => 'datetime',
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * Unique key of the direct chat between two people, whatever the order.
     */
    public static function directKeyFor(int $firstUserId, int $secondUserId): string
    {
        return min($firstUserId, $secondUserId).':'.max($firstUserId, $secondUserId);
    }

    public function isGroup(): bool
    {
        return $this->type === TeamThreadType::Group;
    }

    public function isDirect(): bool
    {
        return $this->type === TeamThreadType::Direct;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Every participation, current and past.
     *
     * @return HasMany<TeamParticipant, $this>
     */
    public function participations(): HasMany
    {
        return $this->hasMany(TeamParticipant::class);
    }

    /**
     * Current participants only.
     *
     * @return HasMany<TeamParticipant, $this>
     */
    public function activeParticipations(): HasMany
    {
        return $this->hasMany(TeamParticipant::class)->whereNull('left_at');
    }

    /**
     * @return HasMany<TeamMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(TeamMessage::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * The current participation of the user, if any.
     */
    public function participationOf(User|int $user): ?TeamParticipant
    {
        $userId = $user instanceof User ? $user->id : $user;

        if ($this->relationLoaded('activeParticipations')) {
            return $this->activeParticipations->firstWhere('user_id', $userId);
        }

        return $this->activeParticipations()->where('user_id', $userId)->first();
    }

    /**
     * Only threads in which the user currently participates.
     *
     * @param  Builder<TeamThread>  $query
     */
    #[Scope]
    protected function withActiveParticipant(Builder $query, User|int $user): void
    {
        $userId = $user instanceof User ? $user->id : $user;

        $query->whereHas('participations', fn (Builder $participations) => $participations
            ->where('user_id', $userId)
            ->whereNull('left_at'));
    }
}
