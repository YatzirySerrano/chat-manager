<?php

namespace App\Actions\Contacts;

use App\Enums\AttributionReliability;
use App\Enums\AuditAction;
use App\Enums\ContactOriginType;
use App\Models\Contact;
use App\Models\ContactAttribution;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class ClassifyContactOrigin
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Record a manual declaration of the contact's origin. It is always a new
     * row: existing attributions, and provider confirmed ones in particular,
     * are never modified. The free-text notes are stored in `body`.
     *
     * @param  array{origin_type: ContactOriginType, ad_platform: string|null, campaign_name: string|null, page_name: string|null, source_url: string|null, notes: string|null}  $data
     */
    public function handle(Contact $contact, array $data, User $actor): ContactAttribution
    {
        return DB::transaction(function () use ($contact, $data, $actor): ContactAttribution {
            $attribution = $contact->attributions()->create([
                'origin_type' => $data['origin_type'],
                'reliability' => AttributionReliability::ManuallyDeclared,
                'ad_platform' => $data['ad_platform'],
                'campaign_name' => $data['campaign_name'],
                'page_name' => $data['page_name'],
                'source_url' => $data['source_url'],
                'body' => $data['notes'],
                'captured_at' => now(),
                'recorded_by_user_id' => $actor->id,
            ]);

            $this->audit->record(AuditAction::ContactOriginClassified, $contact, [
                'attribution_id' => $attribution->id,
                'origin_type' => $attribution->origin_type->value,
                'reliability' => $attribution->reliability->value,
            ], $actor);

            return $attribution;
        });
    }
}
