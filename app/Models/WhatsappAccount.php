<?php

namespace App\Models;

use Database\Factories\WhatsappAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A WhatsApp Business phone number registered in Meta.
 *
 * @property int $id
 * @property string $name
 * @property string $display_phone_number
 * @property string $phone_number_id
 * @property string $business_account_id
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'display_phone_number', 'phone_number_id', 'business_account_id'])]
class WhatsappAccount extends Model
{
    /** @use HasFactory<WhatsappAccountFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
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
