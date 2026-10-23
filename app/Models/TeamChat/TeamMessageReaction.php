<?php

namespace App\Models\TeamChat;

use App\Models\User;
use Database\Factories\TeamChat\TeamMessageReactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A participant's reaction (an emoji of the allow list) to an internal
 * message. Reactions notify nobody and are private to the thread.
 *
 * @property int $id
 * @property int $team_message_id
 * @property int $user_id
 * @property string $emoji
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['emoji'])]
class TeamMessageReaction extends Model
{
    /** @use HasFactory<TeamMessageReactionFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<TeamMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(TeamMessage::class, 'team_message_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
