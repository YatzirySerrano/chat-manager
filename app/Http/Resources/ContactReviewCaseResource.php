<?php

namespace App\Http\Resources;

use App\Models\Contact;
use App\Models\ContactReviewCase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * A possible duplicate as shown to administrators with organization-wide
 * scope. The candidates must be set as the "candidates" relation.
 *
 * @mixin ContactReviewCase
 */
class ContactReviewCaseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Collection<int, Contact> $candidates */
        $candidates = $this->resource->relationLoaded('candidates') ? $this->resource->getRelation('candidates') : collect();

        return [
            'id' => $this->id,
            'reason' => $this->reason->value,
            'reason_label' => $this->reason->label(),
            'status' => $this->status->value,
            'resolution' => $this->resolution?->value,
            'resolution_label' => $this->resolution?->label(),
            'resolved_contact_id' => $this->resolved_contact_id,
            'resolved_by' => $this->whenLoaded('resolver', fn (): ?string => $this->resolver?->name),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'contact' => $this->summary($this->contact),
            'candidates' => $candidates->map(fn (Contact $candidate): array => $this->summary($candidate))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Contact $contact): array
    {
        return [
            'id' => $contact->id,
            'name' => $contact->name,
            'phone_e164' => $contact->phone_e164,
            'email' => $contact->email,
            'profile_name' => $contact->profile_name,
            'status' => $contact->status->value,
            'source' => $contact->source->value,
            'source_label' => $contact->source->label(),
            'whatsapp_ids' => $contact->identities->pluck('external_id')->values()->all(),
            'created_at' => $contact->created_at?->toIso8601String(),
        ];
    }
}
