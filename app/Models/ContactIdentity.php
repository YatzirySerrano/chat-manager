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
 * @property string|null $avatar_disk Disk of the copy of the WhatsApp profile picture (WhatsApp Web only).
 * @property string|null $avatar_path
 * @property string|null $avatar_hash SHA-256 of the stored picture.
 * @property string|null $profile_about The "about" text of the WhatsApp profile.
 * @property Carbon|null $profile_about_set_at
 * @property array<string, mixed>|null $business_profile Public profile of a WhatsApp business account.
 * @property Carbon|null $profile_fetched_at Last time WhatsApp was asked for the profile.
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
            'profile_about_set_at' => 'datetime',
            'business_profile' => 'array',
            'profile_fetched_at' => 'datetime',
        ];
    }

    /**
     * Whether a copy of the WhatsApp profile picture is stored.
     */
    public function hasAvatar(): bool
    {
        return is_string($this->avatar_disk) && $this->avatar_disk !== '' && is_string($this->avatar_path) && $this->avatar_path !== '';
    }

    /**
     * Short version of the stored picture, for URLs the browser may cache:
     * a new picture gets a new URL.
     */
    public function avatarVersion(): ?string
    {
        return $this->hasAvatar() ? substr(sha1($this->avatar_hash ?? (string) $this->avatar_path), 0, 12) : null;
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
