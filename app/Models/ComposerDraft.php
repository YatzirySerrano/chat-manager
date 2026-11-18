<?php

namespace App\Models;

use App\Models\TeamChat\TeamThread;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The unsent text of one user's composer in one chat: a customer
 * conversation (modes «reply» and «note») or an internal thread (mode
 * «message»), never both. Only its owner ever reads it; sending the
 * message removes it (ComposerDrafts).
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $conversation_id
 * @property int|null $team_thread_id
 * @property string $mode
 * @property string $body
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'conversation_id', 'team_thread_id', 'mode', 'body'])]
class ComposerDraft extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<TeamThread, $this>
     */
    public function teamThread(): BelongsTo
    {
        return $this->belongsTo(TeamThread::class);
    }
}
