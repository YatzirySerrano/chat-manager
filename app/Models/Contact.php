<?php

namespace App\Models;

use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A WhatsApp customer identified by its `wa_id`.
 *
 * @property int $id
 * @property string $wa_id
 * @property string $phone_number
 * @property string|null $profile_name
 * @property string|null $display_name
 * @property string|null $email
 * @property string|null $notes
 * @property Carbon|null $last_inbound_at
 * @property Carbon|null $blocked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['wa_id', 'phone_number', 'profile_name', 'display_name', 'email', 'notes'])]
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_inbound_at' => 'datetime',
            'blocked_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
