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
 * @property string|null $navigation_hint Shown under the options of a submenu; null uses the default («0 - Volver…»).
 * @property int $timeout_minutes Minutes without an answer before the ordinary routing takes over (0: never).
 * @property int $max_attempts Answers that are not an option accepted before the ordinary routing takes over.
 * @property int|null $updated_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['is_enabled', 'greeting', 'retry_message', 'navigation_hint', 'max_attempts', 'timeout_minutes'])]
class ChatbotMenu extends Model
{
    public const DEFAULT_RETRY_MESSAGE = 'Responde con el número de una opción.';

    public const MAX_ATTEMPTS_LIMIT = 5;

    public const DEFAULT_NAVIGATION_HINT = '0 - Volver al menú anterior
# - Volver al menú principal';

    public const DEFAULT_TIMEOUT_MINUTES = 30;

    public const MAX_TIMEOUT_MINUTES = 1440;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_enabled' => false,
        'max_attempts' => 2,
        'timeout_minutes' => self::DEFAULT_TIMEOUT_MINUTES,
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
            'timeout_minutes' => 'integer',
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
        return $this->hasMany(ChatbotMenuOption::class)->whereNull('parent_id')->orderBy('position')->orderBy('id');
    }

    /**
     * All the options of every level, in order (the tree is built in memory).
     *
     * @return HasMany<ChatbotMenuOption, $this>
     */
    public function allOptions(): HasMany
    {
        return $this->hasMany(ChatbotMenuOption::class)->orderBy('position')->orderBy('id');
    }

    public function navigationText(): string
    {
        $text = trim((string) $this->navigation_hint);

        return $text === '' ? self::DEFAULT_NAVIGATION_HINT : $text;
    }

    public function retryText(): string
    {
        $text = trim((string) $this->retry_message);

        return $text === '' ? self::DEFAULT_RETRY_MESSAGE : $text;
    }
}
