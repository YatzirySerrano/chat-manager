<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person an attention alert was sent to. `delivered_at` is the first
 * time their application showed it; `acknowledged_at` is when they
 * confirmed it with «Entendido». A delivered alert is not assumed read.
 *
 * @property int $id
 * @property int $attention_alert_id
 * @property int $user_id
 * @property Carbon|null $delivered_at
 * @property Carbon|null $acknowledged_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AttentionAlertRecipient extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AttentionAlert, $this>
     */
    public function alert(): BelongsTo
    {
        return $this->belongsTo(AttentionAlert::class, 'attention_alert_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
