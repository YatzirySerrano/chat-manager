<?php

namespace App\Http\Resources;

use App\Enums\AttributionReliability;
use App\Models\ContactAttribution;
use App\Support\ProtectedAccounts;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Origin evidence as shown in the interface. Raw provider payloads and
 * click identifiers are never exposed.
 *
 * @mixin ContactAttribution
 */
class ContactAttributionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $confirmed = $this->reliability === AttributionReliability::ProviderConfirmed;

        return [
            'id' => $this->id,
            'origin_type' => $this->origin_type->value,
            'origin_label' => $this->origin_type->label(),
            'reliability' => $this->reliability->value,
            'reliability_label' => $confirmed && $this->provider === 'meta'
                ? 'Confirmado por Meta'
                : $this->reliability->label(),
            'provider' => $this->provider,
            'ad_platform' => $this->ad_platform,
            'source_id' => $this->source_id,
            'source_type' => $this->source_type,
            'source_url' => $this->source_url,
            'headline' => $this->headline,
            'body' => $this->body,
            'campaign_id' => $this->campaign_id,
            'campaign_name' => $this->campaign_name,
            'page_name' => $this->page_name,
            'media_type' => $this->media_type,
            'captured_at' => $this->captured_at->toIso8601String(),
            'recorded_by' => $this->whenLoaded('recorder', fn (): ?array => $this->recorder === null ? null : [
                'id' => $this->recorder->id,
                'name' => app(ProtectedAccounts::class)->nameForRequest($this->recorder, $request),
            ]),
        ];
    }
}
