<?php

namespace App\Models;

use App\Enums\AttributionReliability;
use App\Enums\ContactOriginType;
use Database\Factories\ContactAttributionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Evidence of how a contact reached the company. Provider confirmed rows
 * (e.g. a Meta Click-to-WhatsApp referral) and manual declarations coexist;
 * neither overwrites the other.
 *
 * @property int $id
 * @property int $contact_id
 * @property int|null $conversation_id
 * @property string|null $provider_message_id Provider message that carried a confirmed referral (Meta `wamid`).
 * @property ContactOriginType $origin_type
 * @property AttributionReliability $reliability
 * @property string|null $provider
 * @property string|null $ad_platform
 * @property string|null $source_id Meta referral `source_id` (ad or post id).
 * @property string|null $source_type
 * @property string|null $source_url
 * @property string|null $headline
 * @property string|null $body
 * @property string|null $ctwa_clid
 * @property string|null $campaign_id
 * @property string|null $campaign_name
 * @property string|null $page_name
 * @property string|null $media_type
 * @property array<string, mixed>|null $raw_referral
 * @property Carbon $captured_at
 * @property int|null $recorded_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'contact_id', 'conversation_id', 'provider_message_id', 'origin_type', 'reliability', 'provider', 'ad_platform', 'source_id', 'source_type',
    'source_url', 'headline', 'body', 'ctwa_clid', 'campaign_id', 'campaign_name', 'page_name', 'media_type', 'raw_referral',
    'captured_at', 'recorded_by_user_id',
])]
class ContactAttribution extends Model
{
    /** @use HasFactory<ContactAttributionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'origin_type' => ContactOriginType::class,
            'reliability' => AttributionReliability::class,
            'raw_referral' => 'array',
            'captured_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
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
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
