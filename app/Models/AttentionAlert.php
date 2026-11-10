<?php

namespace App\Models;

use App\Enums\AttentionAlertAudience;
use App\Enums\AttentionAlertPriority;
use App\Enums\AttentionAlertSound;
use App\Enums\ColorSwatch;
use Database\Factories\AttentionAlertFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An alert an administrator sent to agents from «Seguimiento de atención».
 * Its content is plain text (shown escaped, never as HTML) and its look and
 * sound come from closed catalogs; each recipient's delivery and
 * acknowledgement live in AttentionAlertRecipient.
 *
 * @property int $id
 * @property int|null $sender_id
 * @property string $client_key
 * @property AttentionAlertAudience $audience
 * @property string $title
 * @property string $body
 * @property AttentionAlertPriority $priority
 * @property ColorSwatch $color
 * @property AttentionAlertSound $sound
 * @property string|null $action
 * @property int $recipients_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AttentionAlert extends Model
{
    /** @use HasFactory<AttentionAlertFactory> */
    use HasFactory;

    /** The alert offers a link to the recipient's conversations waiting for their answer. */
    public const ACTION_PENDING_CONVERSATIONS = 'pending_conversations';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'audience' => AttentionAlertAudience::class,
            'priority' => AttentionAlertPriority::class,
            'color' => ColorSwatch::class,
            'sound' => AttentionAlertSound::class,
            'recipients_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * @return HasMany<AttentionAlertRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(AttentionAlertRecipient::class);
    }
}
