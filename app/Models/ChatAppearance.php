<?php

namespace App\Models;

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
 * without conversation is the user's default for every chat; a row with a
 * conversation overrides it for that chat. conversation_key is a stored
 * generated column (conversation_id or 0) that makes (user_id,
 * conversation_key) unique; it is never written.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $conversation_id
 * @property int $conversation_key
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

    public function isDefault(): bool
    {
        return $this->conversation_id === null;
    }

    /**
     * The user's default appearance (the row without conversation).
     *
     * @param  Builder<ChatAppearance>  $query
     */
    #[Scope]
    protected function defaults(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('conversation_id'));
    }
}
