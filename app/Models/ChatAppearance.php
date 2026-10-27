<?php

namespace App\Models;

use App\Models\TeamChat\TeamThread;
use Database\Factories\ChatAppearanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Personal chat colors of a user, applied only to their own view. The row
 * without chat (no conversation and no internal thread) is the user's
 * default for every chat, customer or internal; a row with a customer
 * conversation or an internal thread (never both) overrides it for that
 * chat. conversation_key and team_thread_key are stored generated columns
 * (the id or 0) that make (user_id, conversation_key, team_thread_key)
 * unique; they are never written.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $conversation_id
 * @property int|null $team_thread_id
 * @property int $conversation_key
 * @property int $team_thread_key
 * @property string|null $preset
 * @property array{light?: array{sent_bg?: string, sent_text?: string, received_bg?: string, received_text?: string, chat_bg?: string}, dark?: array{sent_bg?: string, sent_text?: string, received_bg?: string, received_text?: string, chat_bg?: string}}|null $colors
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['conversation_id', 'preset', 'colors'])]
class ChatAppearance extends Model
{
    /** @use HasFactory<ChatAppearanceFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'colors' => 'array',
            'conversation_key' => 'integer',
            'team_thread_key' => 'integer',
        ];
    }

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

    public function isDefault(): bool
    {
        return $this->conversation_id === null && $this->team_thread_id === null;
    }

    /**
     * The chat this row overrides, or null for the user's default.
     */
    public function chat(): Conversation|TeamThread|null
    {
        return match (true) {
            $this->conversation_id !== null => $this->conversation,
            $this->team_thread_id !== null => $this->teamThread,
            default => null,
        };
    }

    /**
     * The user's default appearance (the row without chat).
     *
     * @param  Builder<ChatAppearance>  $query
     */
    #[Scope]
    protected function defaults(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('conversation_id'))->whereNull($query->qualifyColumn('team_thread_id'));
    }
}
