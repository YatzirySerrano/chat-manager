<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * «Chatbot · Palabras clave»: when a customer message contains one of the
 * keywords (whole words, ignoring case and accents), the reply is sent
 * automatically and the collaborators are told (ReplyToChatbotKeyword).
 *
 * Without connections it applies to every connection; without departments
 * to every department (and to conversations without one). The same rule
 * answers the same conversation at most once per `throttle_minutes`.
 *
 * @property int $id
 * @property string $name
 * @property list<string> $keywords
 * @property string $reply_message
 * @property bool $is_active
 * @property int $throttle_minutes
 * @property int $position
 * @property int|null $created_by_user_id
 * @property int|null $updated_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'keywords', 'reply_message', 'is_active', 'throttle_minutes', 'position'])]
class ChatbotKeywordRule extends Model
{
    public const DEFAULT_THROTTLE_MINUTES = 30;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'throttle_minutes' => self::DEFAULT_THROTTLE_MINUTES,
        'position' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'is_active' => 'boolean',
            'throttle_minutes' => 'integer',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<Connection, $this>
     */
    public function connections(): BelongsToMany
    {
        return $this->belongsToMany(Connection::class, 'chatbot_keyword_rule_connection');
    }

    /**
     * @return BelongsToMany<Department, $this>
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'chatbot_keyword_rule_department');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * @param  Builder<ChatbotKeywordRule>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
