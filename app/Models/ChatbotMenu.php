<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The chatbot's welcome menu of one connection: when a customer starts a new
 * conversation, the greeting is sent followed by the numbered options; the
 * answer sends the conversation to the department of the chosen option
 * (App\Services\Chatbot\ChatbotConversations).
 *
 * @property int $id
 * @property int $connection_id
 * @property bool $is_enabled
 * @property string|null $greeting
 * @property string|null $retry_message Sent after an answer that is not an option; null uses the default text.
 * @property int $max_attempts Answers that are not an option accepted before the ordinary routing takes over.
 * @property int|null $updated_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['is_enabled', 'greeting', 'retry_message', 'max_attempts'])]
class ChatbotMenu extends Model
{
    public const DEFAULT_RETRY_MESSAGE = 'Responde con el número de una opción.';

    public const MAX_ATTEMPTS_LIMIT = 5;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_enabled' => false,
        'max_attempts' => 2,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'max_attempts' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Connection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    /**
     * @return HasMany<ChatbotMenuOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(ChatbotMenuOption::class)->orderBy('position')->orderBy('id');
    }

    public function retryText(): string
    {
        $text = trim((string) $this->retry_message);

        return $text === '' ? self::DEFAULT_RETRY_MESSAGE : $text;
    }
}
