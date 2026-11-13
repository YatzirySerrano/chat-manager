<?php

namespace App\Models;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $conversation_id
 * @property string|null $whatsapp_message_id
 * @property string|null $client_reference
 * @property MessageDirection $direction
 * @property MessageType $type
 * @property MessageStatus $status
 * @property string|null $body
 * @property int|null $sender_user_id
 * @property int|null $reply_to_message_id
 * @property array<string, mixed>|null $metadata
 * @property string|null $error_code
 * @property string|null $error_message
 * @property Carbon|null $sent_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $read_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $edited_at The sender edited it (the body is the latest text).
 * @property Carbon|null $revoked_at The sender deleted it for everyone; the row is kept.
 * @property bool $is_historical Imported from the history of a number linked by QR, never received live.
 * @property Carbon|null $accepted_at Meta's API returned the message id.
 * @property Carbon|null $sending_started_at A worker claimed the message and called Meta.
 * @property int $delivery_attempts
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['conversation_id', 'whatsapp_message_id', 'client_reference', 'direction', 'type', 'status', 'body', 'sender_user_id', 'reply_to_message_id', 'metadata'])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'type' => MessageType::class,
            'status' => MessageStatus::class,
            'metadata' => 'array',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
            'edited_at' => 'datetime',
            'revoked_at' => 'datetime',
            'is_historical' => 'boolean',
            'accepted_at' => 'datetime',
            'sending_started_at' => 'datetime',
            'delivery_attempts' => 'integer',
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
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_to_message_id');
    }

    /**
     * @return HasMany<MessageAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }
}
