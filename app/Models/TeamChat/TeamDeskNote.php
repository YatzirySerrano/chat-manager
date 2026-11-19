<?php

namespace App\Models\TeamChat;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An internal note of the «Mesa de control» team on a request: only the
 * people who attend the desk read it; the requester never does.
 *
 * @property int $id
 * @property int $team_thread_id
 * @property int|null $user_id
 * @property string $body
 * @property Carbon|null $edited_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['body'])]
class TeamDeskNote extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['edited_at' => 'datetime'];
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
}
