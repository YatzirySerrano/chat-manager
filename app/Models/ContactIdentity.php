<?php

namespace App\Models;

use App\Enums\ConnectionChannel;
use App\Enums\ConnectionProvider;
use Database\Factories\ContactIdentityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * How a provider identifies a contact (for WhatsApp, the `wa_id`). Unique per
 * channel, so retried or parallel webhooks never duplicate a person.
 *
 * @property int $id
 * @property int $contact_id
 * @property ConnectionChannel $channel
 * @property ConnectionProvider $provider
 * @property string $external_id
 * @property string|null $display_name Latest profile name sent by the provider.
 * @property Carbon|null $last_seen_at Newest provider event processed for this identity.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['contact_id', 'channel', 'provider', 'external_id', 'display_name', 'last_seen_at'])]
class ContactIdentity extends Model
{
    /** @use HasFactory<ContactIdentityFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => ConnectionChannel::class,
            'provider' => ConnectionProvider::class,
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
