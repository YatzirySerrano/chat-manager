<?php

namespace App\Http\Resources;

use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Tag;
use App\Services\ResolveContactOrigin;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Contact
 */
class ContactResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'profile_name' => $this->profile_name,
            'phone_e164' => $this->phone_e164,
            'email' => $this->email,
            'status' => $this->status->value,
            'is_deleted' => $this->isDeleted(),
            'deleted_at' => $this->archived_at?->toIso8601String(),
            'source' => $this->source->value,
            'source_label' => $this->source->label(),
            'custom_fields' => $this->custom_fields ?? (object) [],
            'first_interaction_at' => $this->first_interaction_at?->toIso8601String(),
            'last_interaction_at' => $this->last_interaction_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'creator' => $this->whenLoaded('creator', fn (): ?array => $this->creator === null ? null : [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),
            'tags' => $this->whenLoaded('tags', fn (): array => $this->tags->map(fn (Tag $tag): array => [
                'id' => $tag->id,
                'name' => $tag->name,
                'color' => $tag->color->value,
                'is_active' => $tag->is_active,
            ])->values()->all()),
            'identities' => $this->whenLoaded('identities', fn (): array => $this->identities->map(fn (ContactIdentity $identity): array => [
                'id' => $identity->id,
                'channel' => $identity->channel->value,
                'channel_label' => $identity->channel->label(),
                'external_id' => $identity->external_id,
                'display_name' => $identity->display_name,
            ])->values()->all()),
            'origin' => $this->whenLoaded('attributions', function () {
                $current = app(ResolveContactOrigin::class)->best($this->attributions);

                return $current === null ? null : [
                    'origin_type' => $current->origin_type->value,
                    'origin_label' => $current->origin_type->label(),
                    'reliability' => $current->reliability->value,
                ];
            }),
            'can' => [
                'view' => $request->user()?->can('view', $this->resource) ?? false,
                'update' => $request->user()?->can('update', $this->resource) ?? false,
                'delete' => $request->user()?->can('delete', $this->resource) ?? false,
                'restore' => $request->user()?->can('restore', $this->resource) ?? false,
            ],
        ];
    }
}
