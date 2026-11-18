<?php

namespace App\Models;

use App\Enums\ChatbotSessionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The welcome menu offered to one conversation (at most one per
 * conversation: the unique key keeps two simultaneous messages from
 * opening it twice) and how it ended (ChatbotSessionStatus).
 *
 * @property int $id
 * @property int $conversation_id
 * @property int $connection_id
 * @property ChatbotSessionStatus $status
 * @property int $attempts Answers that were not an option.
 * @property int|null $chatbot_menu_option_id
 * @property int|null $department_id Where the chosen option sent the conversation.
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ChatbotSession extends Model
{
    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'awaiting',
        'attempts' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ChatbotSessionStatus::class,
            'attempts' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<ChatbotMenuOption, $this>
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(ChatbotMenuOption::class, 'chatbot_menu_option_id');
    }

    public function isAwaiting(): bool
    {
        return $this->status === ChatbotSessionStatus::Awaiting;
    }
}
