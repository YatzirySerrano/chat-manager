<?php

namespace App\Http\Resources;

use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Tag
 */
class TagResource extends JsonResource
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
            'slug' => $this->slug,
            'color' => $this->color->value,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'contacts_count' => $this->whenCounted('contacts'),
            'conversations_count' => $this->whenCounted('conversations'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
